<?php

namespace App\Services\BankStatements;

use App\Enums\BankStatementDirection;
use App\Exceptions\BankStatementXmlException;
use App\Models\CompanyBankAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Parses Lithuanian bank account statement PDFs (SEB "Sąskaitos išrašas" and similar).
 * Uses embedded PDF text when readable; otherwise OCR.space table mode.
 * Multi-account PDFs are split on each “Sąskaitos išrašas” section.
 */
class BankStatementPdfParser
{
    /**
     * @return array{
     *     message_id: ?string,
     *     statement_id: ?string,
     *     account_iban: ?string,
     *     bank_name: ?string,
     *     currency: ?string,
     *     period_from: ?string,
     *     period_to: ?string,
     *     transactions: list<array{
     *         booking_date: string,
     *         value_date: ?string,
     *         direction: BankStatementDirection,
     *         amount: float,
     *         currency: string,
     *         account_iban: ?string,
     *         bank_name: ?string,
     *         counterpart_name: ?string,
     *         counterpart_iban: ?string,
     *         counterpart_bic: ?string,
     *         description: ?string,
     *         end_to_end_id: ?string,
     *         account_servicer_ref: ?string,
     *         transaction_id: ?string
     *     }>
     * }
     */
    public function parse(string $path): array
    {
        if (! is_file($path)) {
            throw new BankStatementXmlException('PDF file was not found.');
        }

        $text = $this->extractText($path);

        if (trim($text) === '') {
            throw new BankStatementXmlException(
                'Could not read text from the PDF bank statement. For scanned PDFs set OCR_SPACE_API_KEY in .env.'
            );
        }

        return $this->parseText($text, basename($path));
    }

    /**
     * @return array{
     *     message_id: ?string,
     *     statement_id: ?string,
     *     account_iban: ?string,
     *     bank_name: ?string,
     *     currency: ?string,
     *     period_from: ?string,
     *     period_to: ?string,
     *     transactions: list<array<string, mixed>>
     * }
     */
    public function parseText(string $text, string $originalFilename = 'statement.pdf'): array
    {
        $text = $this->normalizeText($text);

        if (! $this->looksLikeAccountStatement($text)) {
            throw new BankStatementXmlException(
                'This PDF does not look like a bank account statement (expected “Sąskaitos išrašas” / account statement).'
            );
        }

        $this->assertOcrCoverageLooksComplete($text);

        if ($this->looksLikeCashflowExport($text)) {
            return $this->parseCashflowExport($text, $originalFilename);
        }

        $sections = $this->splitStatementSections($text);
        $transactions = [];
        $accountIbans = [];
        $bankName = $this->extractBankName($text);
        $currency = $this->extractCurrency($text) ?? 'EUR';
        $periodFrom = null;
        $periodTo = null;

        foreach ($sections as $section) {
            $sectionIban = $this->extractAccountIban($section);
            $sectionBank = $this->extractBankName($section) ?? $bankName;
            $sectionCurrency = $this->extractCurrency($section) ?? $currency;
            [$sectionFrom, $sectionTo] = $this->extractPeriod($section);

            if ($sectionFrom !== null && ($periodFrom === null || $sectionFrom < $periodFrom)) {
                $periodFrom = $sectionFrom;
            }
            if ($sectionTo !== null && ($periodTo === null || $sectionTo > $periodTo)) {
                $periodTo = $sectionTo;
            }

            if ($sectionIban !== null) {
                $accountIbans[$sectionIban] = true;
            }

            $sectionTx = $this->extractTransactions($section, $sectionIban, $sectionBank, $sectionCurrency);
            foreach ($sectionTx as $row) {
                $transactions[] = $row;
            }
        }

        if ($transactions === []) {
            throw new BankStatementXmlException('No income or outcome lines were found in the PDF bank statement.');
        }

        usort(
            $transactions,
            function (array $left, array $right): int {
                $byDate = strcmp((string) $left['booking_date'], (string) $right['booking_date']);
                if ($byDate !== 0) {
                    return $byDate;
                }

                return strcmp((string) ($left['account_iban'] ?? ''), (string) ($right['account_iban'] ?? ''));
            },
        );

        $dates = array_column($transactions, 'booking_date');
        $ibanList = array_keys($accountIbans);

        return [
            'message_id' => null,
            'statement_id' => pathinfo($originalFilename, PATHINFO_FILENAME) ?: null,
            'account_iban' => $ibanList[0] ?? $this->extractAccountIban($text),
            'bank_name' => $bankName,
            'currency' => $currency,
            'period_from' => $periodFrom ?? min($dates),
            'period_to' => $periodTo ?? max($dates),
            'transactions' => $transactions,
        ];
    }

    /**
     * Split a multi-account statement PDF into one text block per account.
     *
     * @return list<string>
     */
    public function splitStatementSections(string $text): array
    {
        $text = $this->normalizeText($text);
        $parts = preg_split(
            '/(?=(?:S[AĄ]SKAITOS\s+I[ŠS]RA[ŠS]AS|ACCOUNT\s+STATEMENT)\b)/iu',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        $sections = [];
        foreach ($parts ?: [] as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if (! $this->looksLikeAccountStatement($part) && $this->extractAccountIban($part) === null) {
                // Leading cover / junk before the first statement title.
                continue;
            }
            $sections[] = $part;
        }

        if ($sections === []) {
            return [$text];
        }

        // If OCR dropped titles but multiple company IBANs appear as statement accounts,
        // keep a single block — extractAccountIban still finds the first; sectioning needs titles.
        return $sections;
    }

    private function extractText(string $path): string
    {
        $pdfText = $this->extractEmbeddedPdfText($path);
        if ($pdfText !== '' && $this->looksLikeCashflowExport($pdfText) && $this->hasCashflowTransactionSignals($pdfText)) {
            return $pdfText;
        }

        if ($pdfText !== '' && $this->looksLikeAccountStatement($pdfText) && $this->hasTransactionSignals($pdfText)) {
            return $pdfText;
        }

        $ocrText = $this->extractWithOcrSpace($path);
        if ($ocrText !== '') {
            return $ocrText;
        }

        return $pdfText;
    }

    private function hasCashflowTransactionSignals(string $text): bool
    {
        return preg_match('/BANKAS[\/\s]*(?:IPL|I[ŠS]M)/iu', $text) === 1
            || preg_match('/\b(?:Įplaukos|Iplaukos|I[ŠS]mokos|Ismokos)\t/iu', $text) === 1;
    }

    private function extractEmbeddedPdfText(string $path): string
    {
        try {
            $text = (new PdfParser)->parseFile($path)->getText();
        } catch (Throwable $exception) {
            Log::info('Bank statement PDF text extraction failed', [
                'file' => basename($path),
                'message' => $exception->getMessage(),
            ]);

            return '';
        }

        return $this->normalizeText((string) $text);
    }

    private function extractWithOcrSpace(string $path): string
    {
        $apiKey = (string) config('services.ocr_space.key', '');
        if ($apiKey === '') {
            return '';
        }

        $pageCount = $this->pdfPageCount($path);
        // Free OCR.space often truncates long PDFs — send short page batches.
        if ($pageCount > 2) {
            $parts = [];
            for ($from = 1; $from <= $pageCount; $from += 2) {
                $to = min($from + 1, $pageCount);
                $chunkPath = $this->writePdfPageRange($path, $from, $to);
                if ($chunkPath === null) {
                    continue;
                }

                try {
                    $chunkText = $this->ocrSpaceUpload($chunkPath, $apiKey);
                    if ($chunkText !== '') {
                        $parts[] = $chunkText;
                    }
                } finally {
                    @unlink($chunkPath);
                }
            }

            $batched = $this->normalizeText(implode("\n", $parts));
            if ($batched !== '' && $this->hasTransactionSignals($batched)) {
                return $batched;
            }
        }

        return $this->ocrSpaceUpload($path, $apiKey);
    }

    private function pdfPageCount(string $path): int
    {
        try {
            $pdf = new \setasign\Fpdi\Fpdi;
            $count = $pdf->setSourceFile($path);

            return max(0, (int) $count);
        } catch (Throwable $exception) {
            Log::info('Bank statement PDF page count failed', [
                'file' => basename($path),
                'message' => $exception->getMessage(),
            ]);

            try {
                return count((new PdfParser)->parseFile($path)->getPages());
            } catch (Throwable) {
                return 0;
            }
        }
    }

    /**
     * Write a temporary PDF containing only pages $from..$to (1-based, inclusive).
     */
    public function writePdfPageRange(string $sourcePath, int $from, int $to): ?string
    {
        if ($from < 1 || $to < $from || ! is_file($sourcePath)) {
            return null;
        }

        try {
            $pdf = new \setasign\Fpdi\Fpdi;
            $pageCount = $pdf->setSourceFile($sourcePath);
            $to = min($to, $pageCount);
            if ($from > $pageCount) {
                return null;
            }

            for ($page = $from; $page <= $to; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }

            $out = storage_path(sprintf(
                'app/bank-statement-ocr-%s-p%d-%d.pdf',
                hash('crc32b', $sourcePath.microtime(true)),
                $from,
                $to,
            ));
            $pdf->Output($out, 'F');

            return is_file($out) ? $out : null;
        } catch (Throwable $exception) {
            Log::warning('Bank statement PDF page split failed', [
                'file' => basename($sourcePath),
                'from' => $from,
                'to' => $to,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function ocrSpaceUpload(string $path, string $apiKey): string
    {
        // Engine 2 rejects `lit`; Engine 1 supports Lithuanian. Fall back to eng/E2.
        foreach ([
            ['language' => 'lit', 'OCREngine' => '1'],
            ['language' => 'eng', 'OCREngine' => '2'],
        ] as $opts) {
            $text = $this->ocrSpaceUploadOnce($path, $apiKey, $opts['language'], $opts['OCREngine']);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function ocrSpaceUploadOnce(string $path, string $apiKey, string $language, string $engine): string
    {
        try {
            $response = Http::timeout(180)
                ->asMultipart()
                ->post('https://api.ocr.space/parse/image', [
                    ['name' => 'apikey', 'contents' => $apiKey],
                    ['name' => 'language', 'contents' => $language],
                    ['name' => 'OCREngine', 'contents' => $engine],
                    ['name' => 'scale', 'contents' => 'true'],
                    ['name' => 'detectOrientation', 'contents' => 'true'],
                    ['name' => 'isOverlayRequired', 'contents' => 'false'],
                    ['name' => 'isTable', 'contents' => 'true'],
                    ['name' => 'filetype', 'contents' => 'PDF'],
                    [
                        'name' => 'file',
                        'contents' => fopen($path, 'r'),
                        'filename' => basename($path),
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::warning('Bank statement PDF OCR failed', [
                'file' => basename($path),
                'language' => $language,
                'engine' => $engine,
                'message' => $exception->getMessage(),
            ]);

            return '';
        }

        if (! $response->ok()) {
            Log::warning('Bank statement PDF OCR HTTP error', [
                'status' => $response->status(),
                'language' => $language,
                'engine' => $engine,
            ]);

            return '';
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return '';
        }

        $errored = (bool) data_get($payload, 'IsErroredOnProcessing', false);
        $exitCode = (int) data_get($payload, 'OCRExitCode', 1);
        $errorMessage = data_get($payload, 'ErrorMessage');
        if (is_array($errorMessage)) {
            $errorMessage = implode('; ', array_filter(array_map('strval', $errorMessage)));
        } else {
            $errorMessage = trim((string) $errorMessage);
        }

        if ($errored || ($exitCode !== 1 && $exitCode !== 2)) {
            Log::warning('Bank statement PDF OCR reported an error', [
                'file' => basename($path),
                'language' => $language,
                'engine' => $engine,
                'exit_code' => $exitCode,
                'message' => $errorMessage !== '' ? $errorMessage : null,
            ]);

            if ($errorMessage !== '' && str_contains(Str::lower($errorMessage), 'page')) {
                throw new BankStatementXmlException(
                    'OCR could not read the full PDF (often a free-tier page limit). '
                    .'Split the statement by account or upload fewer pages, then try again. '
                    .$errorMessage
                );
            }

            return '';
        }

        $parts = [];
        foreach ((array) data_get($payload, 'ParsedResults', []) as $row) {
            $pageError = trim((string) data_get($row, 'ErrorMessage', ''));
            if ($pageError !== '') {
                Log::warning('Bank statement PDF OCR page error', [
                    'file' => basename($path),
                    'message' => $pageError,
                ]);
            }

            $page = trim((string) data_get($row, 'ParsedText', ''));
            if ($page !== '') {
                $parts[] = $page;
            }
        }

        $text = $this->normalizeText(implode("\n", $parts));

        if ($text !== '' && $errorMessage !== '' && ! $this->hasTransactionSignals($text)) {
            throw new BankStatementXmlException(
                'OCR failed to extract bank statement lines. '.$errorMessage
            );
        }

        return $text;
    }

    /**
     * SEB footers look like “Puslapis 1(7)”. If OCR only returned early pages, fail loudly.
     */
    private function assertOcrCoverageLooksComplete(string $text): void
    {
        $maxSeen = 0;
        $declaredTotal = 0;

        if (preg_match_all('/Puslapis\s+(\d+)\s*\(\s*(\d+)/iu', $text, $matches, PREG_SET_ORDER) < 1) {
            return;
        }

        foreach ($matches as $match) {
            $maxSeen = max($maxSeen, (int) $match[1]);
            $declaredTotal = max($declaredTotal, (int) $match[2]);
        }

        if ($declaredTotal >= 2 && $maxSeen < $declaredTotal) {
            throw new BankStatementXmlException(sprintf(
                'OCR only returned pages 1–%d of %d. The free OCR limit often cuts long multi-account PDFs. '
                .'Upload each account as a separate PDF (or a shorter file), then re-import.',
                $maxSeen,
                $declaredTotal,
            ));
        }
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace("\0", '', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Keep tabs (table OCR); collapse other runs of spaces.
        $text = preg_replace('/[^\S\t\n]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function looksLikeAccountStatement(string $text): bool
    {
        $normalized = Str::lower($text);

        return Str::contains($normalized, [
            'sąskaitos išrašas',
            'saskaitos israsas',
            'account statement',
            'pradinis likutis',
            'galutinis likutis',
            'operacijos tipas',
            'piniginių lėšų judėjimas',
            'piniginiu lesu judejimas',
            'plesjud',
            'įplaukos',
            'iplaukos',
            'išmokos',
            'ismokos',
        ]);
    }

    private function looksLikeCashflowExport(string $text): bool
    {
        $normalized = Str::lower($text);

        if (Str::contains($normalized, [
            'piniginių lėšų judėjimas',
            'piniginiu lesu judejimas',
            'plesjud',
        ])) {
            return true;
        }

        $hasIncome = Str::contains($normalized, ['įplaukos', 'iplaukos']);
        $hasOutcome = Str::contains($normalized, ['išmokos', 'ismokos']);

        return $hasIncome
            && $hasOutcome
            && preg_match('/\d+\s*-\s*.+\(\d{4}\)\s*[A-Z]{3}/u', $text) === 1;
    }

    private function hasTransactionSignals(string $text): bool
    {
        return preg_match('/\d{4}-\d{2}-\d{2}.{0,120}[+-]?\d+[.,]\d{2}/u', $text) === 1
            || preg_match('/\d{4}-\d{2}-\d{2}\t/u', $text) === 1;
    }

    private function extractAccountIban(string $text): ?string
    {
        // Prefer the statement account near the title, not a counterpart IBAN.
        if (preg_match('/S[AĄ]SKAITOS\s+I[ŠS]RA[ŠS]AS[\s\S]{0,400}?\b(LT\d{18})\b/iu', $text, $near) === 1) {
            return strtoupper($near[1]);
        }

        // After title-less OCR noise: IBAN near opening balance.
        if (preg_match('/\b(LT\d{18})\b[\s\S]{0,120}?Pradinis\s+likutis/iu', $text, $near) === 1) {
            return strtoupper($near[1]);
        }

        if (preg_match('/\b(LT\d{18})\b/u', $text, $m) === 1) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function extractBankName(string $text): ?string
    {
        if (preg_match('/\bAB\s+SEB\s+bankas\b/iu', $text) === 1 || preg_match('/\bSEB\b/u', $text) === 1) {
            return 'SEB';
        }

        if (preg_match('/\bSwedbank\b/iu', $text) === 1) {
            return 'Swedbank';
        }

        if (preg_match('/\bLuminor\b/iu', $text) === 1) {
            return 'Luminor';
        }

        return null;
    }

    private function extractCurrency(string $text): ?string
    {
        if (preg_match('/\bPradinis\s+likutis\b[^\n]*\b(EUR|USD|GBP)\b/iu', $text, $m) === 1) {
            return strtoupper($m[1]);
        }

        if (preg_match('/\b(EUR|USD|GBP)\b/u', $text, $m) === 1) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * Accounting cash-flow PDF export (PLESJUD_01 / Piniginių lėšų judėjimas).
     *
     * @return array{
     *     message_id: ?string,
     *     statement_id: ?string,
     *     account_iban: ?string,
     *     bank_name: ?string,
     *     currency: ?string,
     *     period_from: ?string,
     *     period_to: ?string,
     *     transactions: list<array<string, mixed>>
     * }
     */
    private function parseCashflowExport(string $text, string $originalFilename): array
    {
        $account = $this->extractCashflowAccountMeta($text);
        $currency = $account['currency'] ?? $this->extractCurrency($text) ?? 'EUR';
        [$periodFrom, $periodTo] = $this->extractPeriod($text);
        $transactions = $this->extractCashflowTransactions(
            $text,
            $account['account_iban'],
            $account['bank_name'],
            $currency,
        );

        if ($transactions === []) {
            throw new BankStatementXmlException('No income or outcome lines were found in the cash-flow PDF.');
        }

        usort(
            $transactions,
            fn (array $left, array $right): int => strcmp((string) $left['booking_date'], (string) $right['booking_date']),
        );

        $dates = array_column($transactions, 'booking_date');

        return [
            'message_id' => null,
            'statement_id' => $account['account_code'] ?? pathinfo($originalFilename, PATHINFO_FILENAME) ?: null,
            'account_iban' => $account['account_iban'],
            'bank_name' => $account['bank_name'],
            'currency' => $currency,
            'period_from' => $periodFrom ?? min($dates),
            'period_to' => $periodTo ?? max($dates),
            'transactions' => $transactions,
        ];
    }

    /**
     * @return array{account_code: ?string, bank_name: ?string, account_iban: ?string, currency: ?string}
     */
    private function extractCashflowAccountMeta(string $text): array
    {
        if (preg_match('/(\d+)\s*-\s*(.+?)\s*\((\d{4})\)\s*([A-Z]{3})\b/u', $text, $matches) === 1) {
            $accountCode = $matches[1];
            $bankName = trim($matches[2]);
            $accountTail = $matches[3];
            $currency = strtoupper($matches[4]);
            $excelKey = 'ACC-'.$accountCode.'-'.$accountTail;

            return [
                'account_code' => $accountCode,
                'bank_name' => $bankName !== '' ? $bankName : null,
                'account_iban' => CompanyBankAccount::canonicalizeStatementAccount($excelKey) ?? $excelKey,
                'currency' => $currency,
            ];
        }

        return [
            'account_code' => null,
            'bank_name' => $this->extractBankName($text),
            'account_iban' => $this->extractAccountIban($text),
            'currency' => $this->extractCurrency($text),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractCashflowTransactions(
        string $text,
        ?string $accountIban,
        ?string $bankName,
        string $currency,
    ): array {
        $lines = preg_split('/\n/u', $text) ?: [];
        $transactions = [];
        $currentDate = null;

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $line) === 1) {
                $currentDate = $line;

                continue;
            }

            if ($currentDate === null) {
                continue;
            }

            // Embedded PDF text: BANKAS/IPL 27Įplaukos {likutis}\t{debetas} {kreditas}{counterpart}
            if (preg_match(
                '/^BANKAS[\/\s]*(?:IPL|I[ŠS]M)\s*\d*(Įplaukos|Iplaukos|I[ŠS]mokos|Ismokos)\s+(\d[\d ]*[.,]\d{2})\s+(\d[\d ]*[.,]\d{2})\s+(\d[\d ]*[.,]\d{2})\s*(.*)$/iu',
                $line,
                $m,
            ) === 1) {
                $operation = (string) $m[1];
                $debit = $this->parseAmountToken((string) $m[3]);
                $credit = $this->parseAmountToken((string) $m[4]);
                $rest = trim((string) $m[5]);
            } elseif (preg_match(
                // OCR table: Iplaukos\tBANKAS/IPL\t27 SEB/D00027\tcounterpart\tdebit\tcredit\tbalance
                '/^(Įplaukos|Iplaukos|I[ŠS]mokos|Ismokos)\tBANKAS[\/\s]*(?:IPL|I[ŠS]M|1[ŠS]M)\t([^\t]+)\t([^\t]+)\t(\d[\d ]*[.,]\d{2})\t(\d[\d ]*[.,]\d{2})\t(\d[\d ]*[.,]\d{2})\s*$/iu',
                $line,
                $m,
            ) === 1) {
                $operation = (string) $m[1];
                $document = trim((string) $m[2]);
                $rest = trim((string) $m[3]);
                $debit = $this->parseAmountToken((string) $m[4]);
                $credit = $this->parseAmountToken((string) $m[5]);
                if (preg_match('/\b(SEB\s*[\/]?\s*[A-Z]?\d[\w\/.-]*)\b/iu', $document, $docMatch) === 1) {
                    $document = trim($docMatch[1]);
                } elseif (preg_match('/\b(\d{2,})\b/', $document, $docMatch) === 1) {
                    $document = $docMatch[1];
                } else {
                    $document = null;
                }
            } else {
                continue;
            }

            $normalizedOp = Str::lower($operation);
            $normalizedOp = strtr($normalizedOp, [
                'į' => 'i',
                'š' => 's',
                'ų' => 'u',
                'ė' => 'e',
                'ą' => 'a',
                'č' => 'c',
                'ę' => 'e',
                'ž' => 'z',
                'ū' => 'u',
            ]);

            if (str_contains($normalizedOp, 'iplauk')) {
                $direction = BankStatementDirection::Credit;
                $amount = $debit;
            } elseif (str_contains($normalizedOp, 'ismok')) {
                $direction = BankStatementDirection::Debit;
                $amount = $credit;
            } else {
                continue;
            }

            if ($amount === null || $amount <= 0) {
                continue;
            }

            if (! isset($document)) {
                $document = null;
                if (preg_match('/(SEB\s*[\/]?\s*[A-Z]?\d[\w\/.-]*|\b\d{2,}\b)\s*$/u', $rest, $docMatch) === 1) {
                    $document = trim($docMatch[1]);
                }

                $counterpartRaw = $rest;
                if ($document !== null && str_ends_with($rest, $document)) {
                    $counterpartRaw = trim(substr($rest, 0, -strlen($document)));
                }
            } else {
                $counterpartRaw = $rest;
            }

            $counterpartRaw = trim(preg_replace('/\s+/u', ' ', $counterpartRaw) ?? $counterpartRaw);

            $counterpart = BankStatementCounterpartResolver::resolve(
                $counterpartRaw !== '' ? $counterpartRaw : null,
                $document,
                $document,
            ) ?? ($counterpartRaw !== '' ? $counterpartRaw : null);

            $description = BankStatementDescriptionCleaner::clean(
                $document ?? ($counterpartRaw !== '' ? $counterpartRaw : null),
                $counterpart,
            );

            $transactions[] = [
                'booking_date' => $currentDate,
                'value_date' => $currentDate,
                'direction' => $direction,
                'amount' => round($amount, 2),
                'currency' => $currency,
                'account_iban' => $accountIban,
                'bank_name' => $bankName,
                'counterpart_name' => $counterpart,
                'counterpart_iban' => null,
                'counterpart_bic' => null,
                'description' => $description !== '' ? $description : null,
                'end_to_end_id' => $document,
                'account_servicer_ref' => null,
                'transaction_id' => $document,
            ];
            unset($document);
        }

        return $transactions;
    }

    private function parseAmountToken(string $value): ?float
    {
        $normalized = str_replace(["\t", ' ', "\xc2\xa0"], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function extractPeriod(string $text): array
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})\s*[-–]\s*(\d{4}-\d{2}-\d{2})/u', $text, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [null, null];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractTransactions(string $text, ?string $accountIban, ?string $bankName, string $currency): array
    {
        $lines = preg_split('/\n/u', $text) ?: [];
        $transactions = [];
        $pending = null;

        $flush = function () use (&$pending, &$transactions, $accountIban, $bankName, $currency): void {
            if ($pending === null) {
                return;
            }

            $amount = (float) $pending['signed_amount'];
            if (abs($amount) < 0.005) {
                $pending = null;

                return;
            }

            $direction = $amount < 0 ? BankStatementDirection::Debit : BankStatementDirection::Credit;
            $counterpartName = BankStatementCounterpartCleaner::clean($pending['counterpart_name'])
                ?? ($pending['counterpart_name'] !== '' ? $pending['counterpart_name'] : null);
            $ref = $pending['operation_ref'] !== '' ? $pending['operation_ref'] : null;

            $rawDescription = trim((string) ($pending['remittance'] !== '' ? $pending['remittance'] : $pending['description']));
            $description = BankStatementDescriptionCleaner::clean(
                $rawDescription !== '' ? $rawDescription : null,
                $counterpartName,
            );

            // Prefer cleaned remittance; never fall back to raw operation channel alone.
            if (($description === null || $description === '') && $pending['remittance'] !== '') {
                $description = BankStatementDescriptionCleaner::clean($pending['remittance'], $counterpartName);
            }

            $transactions[] = [
                'booking_date' => $pending['booking_date'],
                'value_date' => $pending['booking_date'],
                'direction' => $direction,
                'amount' => round(abs($amount), 2),
                'currency' => $currency,
                'account_iban' => $accountIban,
                'bank_name' => $bankName,
                'counterpart_name' => $counterpartName,
                'counterpart_iban' => $pending['counterpart_iban'] !== '' ? $pending['counterpart_iban'] : null,
                'counterpart_bic' => $pending['counterpart_bic'] !== '' ? $pending['counterpart_bic'] : null,
                'description' => $description,
                'end_to_end_id' => $ref,
                'account_servicer_ref' => $ref,
                'transaction_id' => $ref,
            ];

            $pending = null;
        };

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $this->isHeaderOrFooterLine($line)) {
                continue;
            }

            $parsedLine = $this->matchTransactionLine($line);
            if ($parsedLine !== null) {
                $flush();

                $counterpartRaw = $parsedLine['counterpart'];
                $iban = null;
                $bic = null;
                if (preg_match('/\b(LT\d{18})\b/u', $counterpartRaw, $ibanMatch) === 1) {
                    $iban = strtoupper($ibanMatch[1]);
                    $counterpartRaw = trim(str_replace($ibanMatch[1], '', $counterpartRaw));
                }
                if (preg_match('/\b([A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?)\b/u', $counterpartRaw, $bicMatch) === 1
                    && ! str_starts_with($bicMatch[1], 'LT')) {
                    if (strlen($bicMatch[1]) >= 8) {
                        $bic = $bicMatch[1];
                    }
                }

                $pending = [
                    'booking_date' => $parsedLine['booking_date'],
                    'operation_type' => $parsedLine['operation_type'],
                    'counterpart_name' => trim($counterpartRaw),
                    'counterpart_iban' => $iban ?? '',
                    'counterpart_bic' => $bic ?? '',
                    'signed_amount' => $this->parseAmount($parsedLine['amount']),
                    'description' => '',
                    'remittance' => '',
                    'operation_ref' => '',
                ];

                continue;
            }

            if ($pending === null) {
                continue;
            }

            // Continuation lines (purpose, bank, refs).
            if (preg_match('/operacijos\s+nr\.?\s*([A-Z0-9]+)/iu', $line, $refMatch) === 1) {
                $pending['operation_ref'] = strtoupper($refMatch[1]);
            }
            if (preg_match('/\b(LT\d{18})\b/u', $line, $ibanMatch) === 1 && $pending['counterpart_iban'] === '') {
                $pending['counterpart_iban'] = strtoupper($ibanMatch[1]);
            }
            if (preg_match('/\b([A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?)\b/u', $line, $bicMatch) === 1
                && $pending['counterpart_bic'] === ''
                && strlen($bicMatch[1]) >= 8
                && ! str_starts_with($bicMatch[1], 'LT')) {
                $pending['counterpart_bic'] = $bicMatch[1];
            }

            $extra = trim(str_replace("\t", ' ', $line));
            if ($extra === '' || $this->isNoiseContinuationLine($extra)) {
                continue;
            }

            // Counterpart name+IBAN wrapped to the next OCR line — keep IBAN, skip name fragment.
            if (preg_match('/^(?:MINISTERIJOS|ministerijos)\b/iu', $extra) === 1
                || preg_match('/^.+\s+LT\d{18}\s*$/u', $extra) === 1) {
                if (preg_match('/\b(LT\d{18})\b/u', $extra, $ibanOnly) === 1 && $pending['counterpart_iban'] === '') {
                    $pending['counterpart_iban'] = strtoupper($ibanOnly[1]);
                }

                continue;
            }

            $purpose = $this->extractRemittancePurpose($extra);
            if ($purpose === '') {
                continue;
            }

            $pending['remittance'] = trim($pending['remittance'].' '.$purpose);
            $pending['description'] = $pending['remittance'];
        }

        $flush();

        return $transactions;
    }

    /**
     * @return array{booking_date: string, operation_type: string, counterpart: string, amount: string}|null
     */
    private function matchTransactionLine(string $line): ?array
    {
        // Preferred: OCR table with tabs. Amount may be "- 131,46" (space after sign).
        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2})\s+([^\t]+?)\t([^\t]*?)\t([+-]?\s*\d{1,3}(?:[ .]\d{3})*,\d{2}|[+-]?\s*\d+,\d{2})\s*$/u',
            $line,
            $m
        ) === 1) {
            return [
                'booking_date' => $m[1],
                'operation_type' => trim($m[2]),
                'counterpart' => trim($m[3]),
                'amount' => $m[4],
            ];
        }

        // Fallback: spaces instead of tabs (common when OCR drops table mode).
        // Keep the minus with the amount when OCR inserts a space ("STATION - 131,46").
        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2})\s+(.+?)\s+([+-]\s*\d{1,3}(?:[ .]\d{3})*,\d{2}|[+-]\s*\d+,\d{2}|\d{1,3}(?:[ .]\d{3})*,\d{2}|\d+,\d{2})\s*$/u',
            $line,
            $m
        ) === 1) {
            $middle = trim($m[2]);
            // First token(s) = operation type until a likely party name / IBAN fragment.
            $operation = $middle;
            $counterpart = '';
            if (preg_match('/^(Mok[eė]j\.?[^\s]*\s+(?:banko\s+viduje|banke)|Tarpt\.?\s+mok[eė]jimas|Europinis\s+mok[eė]jimas|Komisinis\s+atlyginimas|Kreditin[eė]\s+operacija|Debetin[eė]\s+operacija|Atsiskaitymas\s+kort\.?)\s+(.+)$/iu', $middle, $split) === 1) {
                $operation = trim($split[1]);
                $counterpart = trim($split[2]);
            }

            return [
                'booking_date' => $m[1],
                'operation_type' => $operation,
                'counterpart' => $counterpart !== '' ? $counterpart : $middle,
                'amount' => $m[3],
            ];
        }

        return null;
    }

    /**
     * Keep invoice / purpose text from a SEB continuation line; drop technical clauses.
     */
    private function extractRemittancePurpose(string $line): string
    {
        $cleaned = BankStatementDescriptionCleaner::clean($line);

        return trim((string) ($cleaned ?? ''));
    }

    private function isNoiseContinuationLine(string $line): bool
    {
        $lower = Str::lower($line);

        if (preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?(?:\s+AB\s+SEB\s+BANKAS)?$/iu', $line) === 1) {
            return true;
        }

        if (preg_match('/^(?:AB\s+SEB\s+BANKAS|SEB\s+bankas|LUMINOR\s+BANK|NORDEA\s+BANK|AS\s+CITADELE|AS\s+BANKA)/iu', $line) === 1) {
            return true;
        }

        if (preg_match('/^(?:LT\d{18}|DE\d{20}|FI\d{16}|SE\d{22}|LV\d{19}|EE\d{18}|PL\d{24}|NL\d{16}|BE\d{14}|FR\d{25}|GB\d{18})$/u', $line) === 1) {
            return true;
        }

        // Pure technical ref lines with no remittance payload.
        if (preg_match('/^(?:dok\.?\s*nr\.?|mok\.?\s*ident|gav\.?\s*ident|operacijos\s+nr\.?|unik\.?\s*mok)/iu', $line) === 1) {
            return true;
        }

        return Str::contains($lower, [
            'puslapis',
            'informuojame',
            'indelininkui',
            '.pdf',
        ]);
    }

    private function isHeaderOrFooterLine(string $line): bool
    {
        $lower = Str::lower($line);

        return Str::contains($lower, [
            'pradinis likutis',
            'galutinis likutis',
            'iš viso',
            'is viso',
            'puslapis',
            'operacijos tipas',
            'sąskaitos išrašas',
            'saskaitos israsas',
            'informuojame',
            'mokėtojas arba',
            'moketojas arba',
        ]);
    }

    private function parseAmount(string $raw): float
    {
        $raw = preg_replace('/\s+/u', '', trim($raw)) ?? '';
        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
        $value = is_numeric($raw) ? (float) $raw : 0.0;

        return $negative ? -abs($value) : $value;
    }
}
