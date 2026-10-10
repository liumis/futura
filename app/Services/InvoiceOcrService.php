<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

class InvoiceOcrService
{
    /**
     * Provider-specific extraction rules for recurring invoice layouts.
     *
     * @var array<string, array<string, string>>
     */
    private array $providerTemplates = [
        'telia' => [
            'provider_name' => '/\b(Telia(?:[^\S\n]+[A-Za-z]+){0,3})\b/i',
            'invoice_date' => '/\b(?:invoice\s*date|date)\b[:\s\-]*([0-3]?\d[\/\.-][01]?\d[\/\.-](?:20)?\d{2}|\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/i',
            'sum_without_vat' => '/\b(?:subtotal|net\s*amount|sum\s*without\s*vat)\b[^\d]{0,20}([\d\.,]+)/i',
            'vat' => '/\b(?:vat|pvm)\b[^\d]{0,20}([\d\.,]+)/i',
            'sum_inc_vat' => '/\b(?:total|amount\s*due|payable)\b[^\d]{0,20}([\d\.,]+)/i',
            'company_vat' => '/\b(?:VAT|PVM)\b[:\s#\-]*([A-Z0-9\-]+)/i',
            'company_email' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        ],
        'tele2' => [
            'provider_name' => '/\b(Tele2(?:[^\S\n]+[A-Za-z]+){0,3})\b/i',
            'invoice_date' => '/\b(?:invoice\s*date|date)\b[:\s\-]*([0-3]?\d[\/\.-][01]?\d[\/\.-](?:20)?\d{2}|\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/i',
            'sum_without_vat' => '/\b(?:subtotal|net\s*total)\b[^\d]{0,20}([\d\.,]+)/i',
            'vat' => '/\b(?:vat|pvm)\b[^\d]{0,20}([\d\.,]+)/i',
            'sum_inc_vat' => '/\b(?:total|amount\s*due)\b[^\d]{0,20}([\d\.,]+)/i',
            'company_vat' => '/\b(?:VAT|PVM)\b[:\s#\-]*([A-Z0-9\-]+)/i',
            'company_email' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        ],
        'bite' => [
            'provider_name' => '/\b(Bite(?:[^\S\n]+[A-Za-z]+){0,3})\b/i',
            'invoice_date' => '/\b(?:invoice\s*date|date)\b[:\s\-]*([0-3]?\d[\/\.-][01]?\d[\/\.-](?:20)?\d{2}|\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/i',
            'sum_without_vat' => '/\b(?:subtotal|net\s*total)\b[^\d]{0,20}([\d\.,]+)/i',
            'vat' => '/\b(?:vat|pvm)\b[^\d]{0,20}([\d\.,]+)/i',
            'sum_inc_vat' => '/\b(?:total|amount\s*due)\b[^\d]{0,20}([\d\.,]+)/i',
            'company_vat' => '/\b(?:VAT|PVM)\b[:\s#\-]*([A-Z0-9\-]+)/i',
            'company_email' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        ],
        'woocommerce' => [
            'provider_name' => '/\b(WooCommerce\s+Ireland\s+Limited)\b/i',
            'invoice_date' => '/\bInvoice\s*date\b[:\s\-]*([0-3]?\d[\/\.-][01]?\d[\/\.-](?:20)?\d{2}|\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/i',
            'sum_without_vat' => '/\bTotal\b[^\d]{0,15}([\d\.,]+)\s+[\d\.,]+\s+[A-Z]{3}\s+[\d\.,]+/i',
            'vat' => '/\bTotal\b[^\d]{0,30}[\d\.,]+\s+([\d\.,]+)\s+[A-Z]{3}\s+[\d\.,]+/i',
            'sum_inc_vat' => '/\bTotal\b[^\d]{0,50}[\d\.,]+\s+[\d\.,]+\s+[A-Z]{3}\s+([\d\.,]+)/i',
            'company_vat' => '/\bVAT\s*#\b[:\s\-]*([A-Z0-9\-]+)/i',
            'company_email' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        ],
        'spiritus' => [
            'provider_name' => '/\b(Spiritus\s+group,\s*MB)\b/i',
            'invoice_date' => '/\b(\d{4}\s*m\.\s*[a-ząčęėįšųūž]+\s*mėn\.\s*\d{1,2}\s*d\.)\b/iu',
            'sum_without_vat' => '/\b(?:Suma\s+be\s+PVM)\b[^\d]{0,20}([\d\s\.,]+)/iu',
            'vat' => '/\b(?:PVM(?:\s*Suma)?)\b[^\d]{0,20}([\d\s\.,]+)/iu',
            'sum_inc_vat' => '/\b(?:Bendra\s+suma|Iš\s+viso)\b[^\d]{0,20}([\d\s\.,]+)/iu',
            'company_vat' => '/\bPVM\s+mokėtojo\s+kodas\b[:\s\-]*([A-Z0-9\-]+)/iu',
            'company_email' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        ],
    ];

    /**
     * Resolve a Filament/Livewire upload state to a local filesystem path.
     */
    public static function resolveUploadedPath(mixed $state): ?string
    {
        if (is_array($state)) {
            $state = array_values($state)[0] ?? null;
        }

        if ($state instanceof TemporaryUploadedFile) {
            foreach (array_filter([
                $state->getRealPath() ?: null,
                $state->getPathname() ?: null,
            ]) as $candidate) {
                if (is_string($candidate) && is_file($candidate) && filesize($candidate) > 0) {
                    return $candidate;
                }
            }

            $filename = $state->getFilename();
            if (is_string($filename) && $filename !== '') {
                foreach (['livewire-tmp/'.$filename, 'private/livewire-tmp/'.$filename] as $relative) {
                    try {
                        $tmpPath = Storage::disk('local')->path($relative);
                    } catch (Throwable) {
                        continue;
                    }
                    if (is_file($tmpPath) && filesize($tmpPath) > 0) {
                        return $tmpPath;
                    }
                }
            }

            if ($state->exists()) {
                $extension = strtolower((string) $state->getClientOriginalExtension()) ?: 'bin';
                $tmp = tempnam(sys_get_temp_dir(), 'invocr_') ?: null;
                if ($tmp !== null) {
                    $named = $tmp.'.'.$extension;
                    @rename($tmp, $named);
                    file_put_contents($named, (string) $state->get());

                    return is_file($named) && filesize($named) > 0 ? $named : null;
                }
            }

            return null;
        }

        if ($state instanceof UploadedFile) {
            $path = $state->getRealPath() ?: $state->getPathname();

            return is_string($path) && is_file($path) ? $path : null;
        }

        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        $state = trim($state);
        if (is_file($state)) {
            return $state;
        }

        foreach (['public', 'local'] as $disk) {
            try {
                $diskPath = Storage::disk($disk)->path($state);
            } catch (Throwable) {
                continue;
            }

            if (is_file($diskPath)) {
                return $diskPath;
            }
        }

        return null;
    }

    /**
     * @return array<string, string|float|null>
     */
    public function extract(string $absoluteFilePath): array
    {
        if (! is_file($absoluteFilePath)) {
            return [];
        }

        $parsed = [];
        $source = 'none';
        $bestText = '';

        if ($this->isPdf($absoluteFilePath)) {
            $pdfText = $this->extractPdfText($absoluteFilePath);
            if ($pdfText !== '' && ! $this->textLooksUnusable($pdfText)) {
                $bestText = $pdfText;
                $parsed = $this->parseRawText($pdfText);
                $source = 'pdf';
            } elseif ($pdfText !== '') {
                // Keep garbled PDF text only as a signal to force OCR.
                $bestText = $pdfText;
            }
        }

        if ($this->needsRemoteOcr($parsed) || $this->textLooksUnusable($bestText) || $parsed === []) {
            $ocrText = $this->extractWithOcrSpace($absoluteFilePath);
            if ($ocrText !== '') {
                $fromOcr = $this->parseRawText($ocrText);
                $parsed = $this->textLooksUnusable($bestText) || $parsed === []
                    ? $fromOcr
                    : $this->preferBetterParse($parsed, $fromOcr);
                $source = $source === 'pdf' ? 'pdf+ocr' : 'ocr';
                if ($this->readableTextScore($ocrText) >= $this->readableTextScore($bestText)) {
                    $bestText = $ocrText;
                }
            }
        }

        if ($this->looksLikeBankStatement($bestText)) {
            Log::info('Invoice OCR skipped bank statement', [
                'file' => basename($absoluteFilePath),
            ]);

            return [];
        }

        if (! filled($parsed['invoice_number'] ?? null)) {
            $fromName = $this->invoiceNumberFromFilename(basename($absoluteFilePath));
            if ($fromName !== null) {
                $parsed['invoice_number'] = $fromName;
            }
        }

        if ($this->needsAiMapping($parsed)) {
            $fromAi = app(InvoiceAiMapper::class)->map($bestText);
            if ($fromAi !== []) {
                $parsed = $this->preferBetterParse($parsed, $fromAi);
                $source .= $source === 'none' ? 'ai' : '+ai';
            }
        }

        Log::info('Invoice OCR extract', [
            'file' => basename($absoluteFilePath),
            'size' => filesize($absoluteFilePath),
            'source' => $source,
            'fields' => array_keys($parsed),
        ]);

        return $this->sanitizeParsed($parsed);
    }

    /**
     * @return array<string, string|float|null>
     */
    public function parseRawText(string $text): array
    {
        $text = self::sanitizeUtf8($text);
        $templateResult = $this->extractWithTemplate($text);

        if (($templateResult['is_own_sales_invoice'] ?? false) === true) {
            return $this->sanitizeParsed(array_filter([
                'provider_company_name' => $templateResult['provider_company_name'] ?? null,
                'provider_company_email' => $templateResult['provider_company_email'] ?? null,
                'provider_company_vat' => $templateResult['provider_company_vat'] ?? null,
                'provider_company_id' => $templateResult['provider_company_id'] ?? null,
                'invoice_number' => $templateResult['invoice_number'] ?? null,
                'invoice_date' => $templateResult['invoice_date'] ?? null,
                'sum_without_vat' => $templateResult['sum_without_vat'] ?? null,
                'vat' => $templateResult['vat'] ?? null,
                'sum_inc_vat' => $templateResult['sum_inc_vat'] ?? null,
                'currency' => $templateResult['currency'] ?? 'EUR',
                'is_own_sales_invoice' => true,
            ], static fn ($value): bool => $value !== null && $value !== ''));
        }

        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/', $text) ?: [],
        )));

        $provider = $templateResult['provider_company_name'] ?? $this->guessProviderName($lines);

        $parsed = array_filter([
            'provider_company_name' => $provider,
            'provider_company_email' => $templateResult['provider_company_email'] ?? $this->extractByRegex($text, '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i'),
            'provider_company_vat' => $templateResult['provider_company_vat']
                ?? $this->extractSellerVat($text),
            'provider_company_id' => $templateResult['provider_company_id']
                ?? $this->extractByRegex($this->textBeforeBuyer($text), '/\b(\d{7,11})\s*=?\s*PVM\s*mok/iu', 1)
                ?? $this->extractByRegex($this->textBeforeBuyer($text), '/(?:[įi]mon[eė]s\s+kodas|kodas)[:\s=]*([0-9]{7,11})/iu', 1)
                ?? $this->extractByRegex($text, '/(?:[įi]mon[eė]s\s+kodas|company\s*(?:code|id)|reg(?:istration)?\s*(?:no\.?|code))[:\s]*([0-9]{7,11})/iu', 1),
            'invoice_number' => $templateResult['invoice_number'] ?? $this->extractInvoiceNumber($text),
            'invoice_date' => $templateResult['invoice_date']
                ?? $this->normalizeDate($this->extractByRegex(
                    $text,
                    '/\b(?:invoice\s*date|date\s*paid|s[aą]skaitos\s+data|i[šs]ra[šs]ymo\s+data|data)\b[:\s\-]*([0-3]?\d[\/\.-][01]?\d[\/\.-](?:20)?\d{2}|\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/iu',
                    1
                ))
                ?? $this->parseEnglishTextDate($text)
                ?? $this->parseLithuanianTextDate($text)
                ?? $this->normalizeDate($this->extractByRegex($text, '/\b(\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)\b/', 1)),
            'sum_without_vat' => $templateResult['sum_without_vat']
                ?? $this->extractAmount($text, '/i[šs]\s+viso(?:\s+eur)?\s+be\s+pvm[:\s]*([\d\s.,]+)/iu')
                ?? $this->extractAmount($text, '/\b(?:subtotal|net\s*total|sum\s*without\s*vat)\b[^\d]{0,40}([\d\s\.,]+)/iu')
                ?? $this->extractAmount($text, '/\bsuma\s+be\s+pvm\b[^\d]{0,12}([\d\s\.,]{4,})/iu'),
            'vat' => $templateResult['vat']
                ?? $this->extractAmount($text, '/\d+\s*%\s*(?:pvm|vat)\s*\(\s*baz[eė][^)]*\)\s*([\d\s.,]+)/iu')
                ?? $this->extractAmount($text, '/\b(?:pvm\s*suma|vat\s*amount)\b[^\d]{0,40}([\d\s\.,]+)/iu')
                ?? $this->extractAmount($text, '/(?<!be\s)\b(?:vat|pvm)\b[^\d]{0,20}([\d\s\.,]+)/iu'),
            'sum_inc_vat' => $templateResult['sum_inc_vat']
                ?? $this->extractAmount($text, '/\b(?:mok[eė]tina\s+suma)(?:\s+eur)?[:\s]*([\d\s.,]+)/iu')
                ?? $this->extractAmount($text, '/\b(?:total|amount\s*due|grand\s*total|sum\s*inc\.?\s*vat|bendra\s+suma|suma\s+su\s+pvm|i[šs]\s+viso)\b[^\d]{0,40}([\d\s\.,]+)/iu'),
            'currency' => $templateResult['currency'] ?? $this->extractCurrency($text),
        ], static fn ($value): bool => $value !== null && $value !== '');

        if (! isset($parsed['sum_without_vat']) && isset($parsed['sum_inc_vat'], $parsed['vat'])) {
            $net = round((float) $parsed['sum_inc_vat'] - (float) $parsed['vat'], 2);
            if ($net >= 0) {
                $parsed['sum_without_vat'] = $net;
            }
        }

        if (isset($parsed['vat'], $parsed['sum_inc_vat']) && (float) $parsed['vat'] > (float) $parsed['sum_inc_vat'] + 0.009) {
            unset($parsed['vat']);
        }

        if (! isset($parsed['vat']) && isset($parsed['sum_without_vat'], $parsed['sum_inc_vat'])) {
            $parsed['vat'] = round(max(0, (float) $parsed['sum_inc_vat'] - (float) $parsed['sum_without_vat']), 2);
        }

        if (preg_match('/pvm\s*neapmok/iu', $text) === 1) {
            $parsed['vat'] = 0.0;
            if (! isset($parsed['sum_inc_vat']) && isset($parsed['sum_without_vat'])) {
                $parsed['sum_inc_vat'] = $parsed['sum_without_vat'];
            }
        }

        return $this->sanitizeParsed($parsed);
    }

    public function hasRemoteOcr(): bool
    {
        return filled(config('services.ocr_space.key'));
    }

    public function hasAiMapping(): bool
    {
        return app(InvoiceAiMapper::class)->isEnabled();
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function needsAiMapping(array $parsed): bool
    {
        if (! app(InvoiceAiMapper::class)->isEnabled()) {
            return false;
        }

        return $this->needsRemoteOcr($parsed) || ! filled($parsed['invoice_number'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function needsRemoteOcr(array $parsed): bool
    {
        $hasAmount = filled($parsed['sum_inc_vat'] ?? null) || filled($parsed['sum_without_vat'] ?? null);
        $hasDate = filled($parsed['invoice_date'] ?? null);
        $name = Str::lower((string) ($parsed['provider_company_name'] ?? ''));
        $weakName = $this->isWeakProviderName($name);

        return ! $hasAmount || ! $hasDate || $weakName;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $fromOcr
     * @return array<string, string|float|null>
     */
    private function preferBetterParse(array $parsed, array $fromOcr): array
    {
        foreach ($fromOcr as $key => $value) {
            if (! filled($parsed[$key] ?? null) && filled($value)) {
                $parsed[$key] = $value;
            }
        }

        $currentName = (string) ($parsed['provider_company_name'] ?? '');
        $ocrName = (string) ($fromOcr['provider_company_name'] ?? '');
        if ($this->isWeakProviderName($currentName) && $ocrName !== '' && ! $this->isWeakProviderName($ocrName)) {
            $parsed['provider_company_name'] = $ocrName;
        }

        return array_filter($parsed, static fn ($value): bool => $value !== null && $value !== '');
    }

    private function isWeakProviderName(string $name): bool
    {
        $name = Str::lower(trim($name));

        return $name === '' || Str::contains($name, [
            'pirk',
            'customer',
            'buyer',
            'bill to',
            'pardav',
            'seller',
            'receipt',
            'futura textiles',
            'apmokėjimo data',
            'apmokejimo data',
            'bank account',
            'number up',
            'skaita fakt',
        ]) || preg_match('/^[0-9,.\-\s$]+$/', $name) === 1;
    }

    private function isPdf(string $absoluteFilePath): bool
    {
        $extension = strtolower((string) pathinfo($absoluteFilePath, PATHINFO_EXTENSION));

        return $extension === 'pdf' || $this->fileStartsWith($absoluteFilePath, '%PDF');
    }

    private function extractPdfText(string $absoluteFilePath): string
    {
        try {
            $text = (new PdfParser)->parseFile($absoluteFilePath)->getText();
        } catch (Throwable $exception) {
            Log::info('Invoice PDF text extraction failed', [
                'file' => basename($absoluteFilePath),
                'message' => $exception->getMessage(),
            ]);

            return '';
        }

        return trim(self::sanitizeUtf8($this->repairExtractedText((string) $text)));
    }

    private function extractWithOcrSpace(string $absoluteFilePath): string
    {
        $apiKey = (string) config('services.ocr_space.key', '');
        if ($apiKey === '') {
            return '';
        }

        try {
            $response = Http::timeout(25)
                ->asMultipart()
                ->post('https://api.ocr.space/parse/image', [
                    ['name' => 'apikey', 'contents' => $apiKey],
                    ['name' => 'language', 'contents' => 'eng'],
                    ['name' => 'OCREngine', 'contents' => '2'],
                    ['name' => 'scale', 'contents' => 'true'],
                    ['name' => 'detectOrientation', 'contents' => 'true'],
                    ['name' => 'isOverlayRequired', 'contents' => 'false'],
                    ['name' => 'filetype', 'contents' => strtoupper((string) pathinfo($absoluteFilePath, PATHINFO_EXTENSION)) ?: 'PDF'],
                    [
                        'name' => 'file',
                        'contents' => fopen($absoluteFilePath, 'r'),
                        'filename' => basename($absoluteFilePath),
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::warning('Invoice OCR request failed', [
                'file' => basename($absoluteFilePath),
                'message' => $exception->getMessage(),
            ]);

            return '';
        }

        if (! $response->ok()) {
            Log::warning('Invoice OCR HTTP error', [
                'status' => $response->status(),
            ]);

            return '';
        }

        $payload = $response->json();
        $ocrError = (string) data_get($payload, 'ErrorMessage.0', data_get($payload, 'ErrorMessage', ''));
        if (is_array($ocrError)) {
            $ocrError = implode(' ', $ocrError);
        }
        if (filled($ocrError)) {
            Log::warning('Invoice OCR API error', ['message' => $ocrError]);
        }

        return trim(self::sanitizeUtf8((string) data_get($payload, 'ParsedResults.0.ParsedText', '')));
    }

    /**
     * @return array<string, string|float|null>
     */
    private function extractWithTemplate(string $text): array
    {
        $providerKey = $this->detectProviderKey($text);
        if ($providerKey === 'dsv') {
            return $this->extractDsvRoad($text);
        }

        if ($providerKey === 'anthropic') {
            return $this->extractAnthropic($text);
        }

        if ($providerKey === 'lt_pvm') {
            return $this->extractLithuanianPvmInvoice($text);
        }

        if ($providerKey === 'german_rechnung') {
            return $this->extractGermanRechnung($text);
        }

        if ($providerKey === 'finvalda') {
            return $this->extractFinvaldaInvoice($text);
        }

        if ($providerKey === 'divcom') {
            return $this->extractDivcomInvoice($text);
        }

        if ($providerKey === 'fte_own') {
            return $this->extractFteOwnSalesInvoice($text);
        }

        if ($providerKey === null) {
            return [];
        }

        $template = $this->providerTemplates[$providerKey] ?? null;
        if ($template === null) {
            return [];
        }

        return [
            'provider_company_name' => $this->extractByRegex($text, $template['provider_name'] ?? '/^$/i'),
            'provider_company_email' => $this->extractByRegex($text, $template['company_email'] ?? '/^$/i'),
            'provider_company_vat' => $this->extractByRegex($text, $template['company_vat'] ?? '/^$/i', 1),
            'invoice_date' => $this->normalizeDate($this->extractByRegex($text, $template['invoice_date'] ?? '/^$/i', 1)),
            'sum_without_vat' => $this->extractAmount($text, $template['sum_without_vat'] ?? '/^$/i'),
            'vat' => $this->extractAmount($text, $template['vat'] ?? '/^$/i'),
            'sum_inc_vat' => $this->extractAmount($text, $template['sum_inc_vat'] ?? '/^$/i'),
        ];
    }

    /**
     * DSV Road UAB freight invoices (PVM sąskaita-faktūra with Inv. No. + B-VAT totals).
     *
     * @return array<string, string|float|null>
     */
    private function extractDsvRoad(string $text): array
    {
        $invoiceDate = null;
        $invoiceNumber = null;
        if (preg_match('/PVM\s+S[AĄ]SKAITA(?:-FAKT[UŪ]RA)?[\s\S]{0,80}?(\d{4}-\d{2}-\d{2})\s+(\d+)\s+(\d+)/iu', $text, $header)) {
            $invoiceDate = $this->normalizeDate($header[1]);
            $invoiceNumber = $header[3];
        }

        $net = null;
        $vat = null;
        $total = null;
        if (preg_match('/B\s*-\s*VAT\s*\d+\s*%\s*[A-Z]{2}\s+([\d.,]+)\s+([\d.,]+)\s+[\d.,]+\s+([\d.,]+)/i', $text, $totals)) {
            $net = $this->parseAmountValue($totals[1]);
            $vat = $this->parseAmountValue($totals[2]);
            $total = $this->parseAmountValue($totals[3]);
        }

        if ($total === null && preg_match('/([\d.,]+)\s+EUR\s+([\d.,]+)\s+EUR\s+([\d.,]+)/i', preg_replace('/\s+/u', ' ', $text) ?? $text, $totals)) {
            $net = $this->parseAmountValue($totals[1]);
            $vat = $this->parseAmountValue($totals[2]);
            $total = $this->parseAmountValue($totals[3]);
        }

        return array_filter([
            'provider_company_name' => 'DSV Road, UAB',
            'provider_company_id' => $this->extractByRegex($text, '/[ĮįI]mon[eė]s\s+kodas[:\s]*([0-9]{7,11})/u', 1),
            'provider_company_vat' => $this->extractByRegex($text, '/(?:VAT\s*reg\.?\s*No\.?|PVM\s*mok\.?\s*kodas)[^\n]{0,50}?(LT\d{8,12})/iu', 1),
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'sum_without_vat' => $net,
            'vat' => $vat,
            'sum_inc_vat' => $total,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Anthropic / Stripe receipts (Invoice number + Date paid + reverse-charge VAT).
     *
     * @return array<string, string|float|null>
     */
    private function extractAnthropic(string $text): array
    {
        $sellerText = $this->textBeforeBuyer($text);

        $invoiceNumber = $this->extractByRegex($text, '/Invoice\s*number[:\s]*([A-Z0-9][A-Z0-9\/\-]{3,40})/i', 1)
            ?? $this->extractByRegex($text, '/Receipt\s*number[:\s]*([0-9]{3,5}[- ][0-9]{3,5}[- ][0-9]{3,5})/i', 1);

        $paidOn = $this->extractByRegex($text, '/(?:Date\s*paid|paid\s+on)[:\s]*([A-Za-z]+\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4})/i', 1);
        $invoiceDate = $this->parseEnglishTextDate((string) ($paidOn ?? $text));

        $vatCode = $this->extractByRegex($sellerText, '/EU\s*VAT[:\s]*([A-Z]{2}[A-Z0-9]{8,12})/i', 1)
            ?? $this->extractByRegex($sellerText, '/VAT\s*Registration[:\s]*[A-Z]{0,4}\s*VAT[:\s]*([A-Z]{2}[A-Z0-9]{8,12})/i', 1);

        $subtotal = $this->extractAmount($text, '/\bSubtotal\b[^\d€]{0,20}€?\s*([\d\s\.,]+)/i');
        $total = $this->extractAmount($text, '/\bAmount\s+paid\b[^\d€]{0,20}€?\s*([\d\s\.,]+)/i')
            ?? $this->extractAmount($text, '/\bTotal\b[^\d€]{0,20}€?\s*([\d\s\.,]+)/i')
            ?? $this->extractAmount($text, '/€\s*([\d\s\.,]+)\s+paid\b/i');

        $vat = 0.0;
        if ($subtotal !== null && $total !== null && $total > $subtotal + 0.009) {
            $vat = round($total - $subtotal, 2);
        } elseif (! preg_match('/reverse\s+charge|\b0\s*%/i', $text)) {
            $vat = $this->extractAmount($sellerText, '/\b(?:vat|tax)\b[^\d]{0,20}([\d\s\.,]+)/i');
        }

        $email = $this->extractByRegex($sellerText, '/[A-Z0-9._%+\-]+@anthropic\.com/i')
            ?? $this->extractByRegex($sellerText, '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i');

        return array_filter([
            'provider_company_name' => 'Anthropic, PBC',
            'provider_company_email' => $email,
            'provider_company_vat' => $vatCode,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'sum_without_vat' => $subtotal ?? $total,
            'vat' => $vat,
            'sum_inc_vat' => $total ?? $subtotal,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Common Lithuanian PVM sąskaita-faktūra layout (Pardavėjas block + Iš viso / Mokėtina suma).
     *
     * @return array<string, string|float|null>
     */
    private function extractLithuanianPvmInvoice(string $text): array
    {
        $sellerText = $this->textBeforeBuyer($text);
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/', $text) ?: [],
        )));

        $net = $this->extractAmount($text, '/i[šs]\s+viso(?:\s+eur)?\s+be\s+pvm[:\s]*([\d\s.,]+)/iu');
        $total = $this->extractAmount($text, '/mok[eė]tina\s+suma(?:\s+eur)?[:\s]*([\d\s.,]+)/iu')
            ?? $this->extractAmount($text, '/tarpin[eė]\s+suma(?:\s+eur)?[:\s]*([\d\s.,]+)/iu');
        $vat = $this->extractAmount($text, '/\d+\s*%\s*PVM\s*\(\s*Baz[eė][^)]*\)\s*([\d\s.,]+)/iu');

        if ($vat !== null && $total !== null && $vat > $total + 0.009) {
            $vat = null;
        }

        if ($vat === null && $net !== null && $total !== null) {
            $vat = round(max(0, $total - $net), 2);
        } elseif ($vat === null && preg_match('/0\s*%\s*PVM/iu', $text) === 1) {
            $vat = 0.0;
        }

        if ($total === null && $net !== null && $vat !== null) {
            $total = round($net + $vat, 2);
        }

        $date = $this->normalizeDate($this->extractByRegex(
            $text,
            '/i[šs]ra[šs]ymo\s+data[:\s]*(\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/iu',
            1,
        ));

        return array_filter([
            'provider_company_name' => $this->nameNearLabels($lines, ['pardavėjas', 'pardavejas']),
            'provider_company_email' => $this->extractByRegex($text, '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i'),
            'provider_company_vat' => $this->extractSellerVat($text),
            'provider_company_id' => $this->extractByRegex($sellerText, '/(?:[įi]mon[eė]s\s+kodas)[:\s]*([0-9]{7,11})/iu', 1),
            'invoice_number' => $this->extractInvoiceNumber($text),
            'invoice_date' => $date,
            'sum_without_vat' => $net ?? $total,
            'vat' => $vat,
            'sum_inc_vat' => $total ?? $net,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function looksLikeLithuanianPvmInvoice(string $normalized): bool
    {
        return (Str::contains($normalized, 'pvm sąskaita') || Str::contains($normalized, 'pvm saskaita'))
            && (Str::contains($normalized, 'pardavėjas') || Str::contains($normalized, 'pardavejas'));
    }

    private function looksLikeGermanRechnung(string $normalized): bool
    {
        return Str::contains($normalized, 'rechnung')
            && (
                Str::contains($normalized, 'bruttobetrag')
                || Str::contains($normalized, 'endsumme')
                || Str::contains($normalized, 'mwst')
            );
    }

    /**
     * German hotel / POS Rechnung (Rechnung + Bruttobetrag / Endsumme, DE thousand separators).
     *
     * @return array<string, string|float|null>
     */
    private function extractGermanRechnung(string $text): array
    {
        $invoiceNumber = $this->extractByRegex($text, '/\bRechnung\s*[:\t ]\s*([0-9]{3,12})\b/iu', 1)
            ?? $this->extractByRegex($text, '/\bAR[- ]?0*([0-9]{3,12})\b/i', 1);

        $invoiceDate = $this->normalizeDate($this->extractByRegex(
            $text,
            '/\bRechnung\b[\s\S]{0,80}?(\d{1,2}\.\d{1,2}\.\d{4})/iu',
            1,
        )) ?? $this->normalizeDate($this->extractByRegex(
            $text,
            '/\b(\d{1,2}\.\d{1,2}\.\d{4})\b/',
            1,
        ));

        $gross = $this->extractAmount($text, '/\b(?:Bruttobetrag|Endsumme|Bezahlter\s+Betrag)\s*:?\s*([\d.]+,\d{2})\s*(?:EUR)?/iu')
            ?? $this->extractAmount($text, '/\bBetrag\s*:\s*([\d.]+,\d{2})\s*EUR/iu');

        $net = null;
        $vat = null;
        if (preg_match('/MwSt\.?\s*Grundlage[\s\S]{0,40}?([\d.]+,\d{2})\s+\d+\s*:\s*\d+\s*%\s*MwSt\.?\s*([\d.]+,\d{2})\s+([\d.]+,\d{2})/iu', $text, $mwst)) {
            $net = $this->parseAmountValue($mwst[1]);
            $vat = $this->parseAmountValue($mwst[2]);
            $gross = $gross ?? $this->parseAmountValue($mwst[3]);
        }

        if ($vat === null && $net !== null && $gross !== null) {
            $vat = round(max(0, $gross - $net), 2);
        }
        if ($net === null && $gross !== null && $vat !== null) {
            $net = round(max(0, $gross - $vat), 2);
        }

        $email = $this->extractByRegex($text, '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i');
        $provider = $this->companyNameFromEmail($email)
            ?? $this->guessProviderNameExcludingOwn($text);

        return array_filter([
            'provider_company_name' => $provider,
            'provider_company_email' => $email,
            'invoice_number' => $invoiceNumber !== null ? $this->normalizeInvoiceNumber($invoiceNumber) : null,
            'invoice_date' => $invoiceDate,
            'sum_without_vat' => $net,
            'vat' => $vat,
            'sum_inc_vat' => $gross,
            'currency' => 'EUR',
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Futura Textiles Europe own sales invoices (SERIJA FTE NR.: … / Pirkėjas counterparty).
     *
     * @return array<string, string|float|null>
     */
    private function extractFteOwnSalesInvoice(string $text): array
    {
        $invoiceNumber = null;
        if (preg_match('/SERIJA\s+(FTE)\s+NR\.?\s*:?\s*(\d+)/iu', $text, $m)) {
            $invoiceNumber = $this->normalizeInvoiceNumber($m[1].'-'.$m[2]);
        }

        $invoiceDate = $this->normalizeDate($this->extractByRegex(
            $text,
            '/(?:PARDAV[EĖ]JAS|PIRK[EĖ]JAS)[^\d]{0,40}(\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/iu',
            1,
        ));
        if ($invoiceDate === null) {
            $invoiceDate = $this->normalizeDate($this->extractByRegex(
                $text,
                '/\b(\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)\b/',
                1,
            ));
        }

        $gross = $this->extractLastAmount($text, '/SUMA\s+APMOK[EĖ]JIMUI\s*:\s*([0-9][0-9., ]*)/iu');
        $net = $this->extractLastAmount($text, '/VISO\s+BE\s+PVM\s*:\s*([0-9][0-9., ]*)/iu');

        if ($gross === null || $gross <= 0.0) {
            // Footer often repeats the payable total after “PVM SUMA:” / “Apmokėti iki”.
            $gross = $this->extractLastAmount($text, '/Apmok[eė]ti\s+iki\s*:\s*\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d\s*([0-9][0-9., ]*)/iu')
                ?? $this->extractLargestEuroLiteral($text);
        }

        $vat = null;
        if (preg_match('/PVM\s+0\s*%/iu', $text) === 1) {
            $vat = 0.0;
        } elseif (preg_match('/PVM\s+21\s*%\s*:?\s*([\d\s.,]+)\s+([\d\s.,]+)/iu', $text, $taxLines) === 1) {
            $net = $net ?? $this->parseAmountValue($taxLines[1]);
            $vat = $this->parseAmountValue($taxLines[2]);
        } else {
            $vat = $this->extractAmount($text, '/PVM\s+SUMA\s*:?\s*([\d\s.,]+)/iu');
        }

        if ($vat === 0.0 && $gross !== null) {
            $net = $net ?? $gross;
        }
        if ($net === null && $gross !== null && $vat !== null) {
            $net = round(max(0, $gross - $vat), 2);
        }
        if ($vat === null && $net !== null && $gross !== null) {
            $vat = round(max(0, $gross - $net), 2);
        }
        if ($gross === null && $net !== null && $vat !== null) {
            $gross = round($net + $vat, 2);
        }

        $buyerName = $this->extractFteBuyerCompanyName($text);
        $buyerVat = $this->extractFteBuyerVat($text);
        $buyerId = $this->extractFteBuyerCompanyId($text);

        return array_filter([
            'provider_company_name' => $buyerName,
            'provider_company_vat' => $buyerVat,
            'provider_company_id' => $buyerId,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'sum_without_vat' => $net,
            'vat' => $vat,
            'sum_inc_vat' => $gross ?? $net,
            'currency' => 'EUR',
            'is_own_sales_invoice' => true,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function looksLikeFteOwnSalesInvoice(string $normalized): bool
    {
        return Str::contains($normalized, 'futura textiles')
            && preg_match('/serija\s+fte\b/u', $normalized) === 1;
    }

    private function extractFteBuyerCompanyName(string $text): ?string
    {
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/u', $text) ?: [],
        )));

        $seenOwn = false;
        foreach ($lines as $line) {
            if ($this->looksLikeOwnCompanyName($line)) {
                $seenOwn = true;

                continue;
            }

            if (! $seenOwn) {
                continue;
            }

            if ($this->looksLikeFteMetaLine($line)) {
                continue;
            }

            if ($this->isPlausibleCompanyName($line)) {
                return Str::limit(trim($line, " \t\n\r\0\x0B:.-"), 255, '');
            }
        }

        return null;
    }

    private function looksLikeFteMetaLine(string $line): bool
    {
        $lower = Str::lower($line);

        return Str::contains($lower, [
            'pardavėjas', 'pardavejas', 'pirkėjas', 'pirkejas',
            'bankas', 'a / s', 'a/s', 'įm. kodas', 'im. kodas',
            'pvm mok', 'ringuvos', 'lietuva', 'lithuania',
            'seria', 'serija', 'sąskaita', 'saskaita',
            'viso be', 'suma apmok', 'apmokėti', 'apmoketi',
        ]) || preg_match('/^\d{4}[\/\.-]\d{2}[\/\.-]\d{2}$/', $line) === 1
            || preg_match('/^(lt|ie|lv|ee)\d/i', $line) === 1;
    }

    private function extractFteBuyerVat(string $text): ?string
    {
        if (! preg_match_all('/\b([A-Z]{2}\d{8,12}|[A-Z]{2}\d{6,9}[A-Z0-9]{0,2})\b/u', strtoupper($text), $matches)) {
            return null;
        }

        $own = ['LT100017651210'];
        foreach ($matches[1] as $code) {
            $code = strtoupper(preg_replace('/\s+/', '', $code) ?? $code);
            if (in_array($code, $own, true)) {
                continue;
            }
            if ($this->looksLikeIban($code)) {
                continue;
            }

            return $code;
        }

        return null;
    }

    private function extractFteBuyerCompanyId(string $text): ?string
    {
        // Buyer Įm. kodas is often blank on FTE layouts; only take an id that is not Futura's.
        if (! preg_match_all('/[ĮIįi]m\.?\s*kodas\s*:\s*([0-9]{7,11})/u', $text, $matches)) {
            return null;
        }

        $own = ['306740042'];
        foreach ($matches[1] as $id) {
            if (! in_array($id, $own, true)) {
                return $id;
            }
        }

        return null;
    }

    private function extractLastAmount(string $text, string $pattern): ?float
    {
        if (! preg_match_all($pattern, $text, $matches)) {
            return null;
        }

        $last = null;
        $lastNonZero = null;
        foreach ($matches[1] as $raw) {
            $amount = $this->parseAmountValue(trim((string) $raw));
            if ($amount === null) {
                continue;
            }
            $last = $amount;
            if ($amount > 0.009) {
                $lastNonZero = $amount;
            }
        }

        return $lastNonZero ?? $last;
    }

    private function extractLargestEuroLiteral(string $text): ?float
    {
        if (! preg_match_all('/\b(\d{1,3}(?:[ \u00A0]?\d{3})*[.,]\d{2}|\d+[.,]\d{2})\b/u', $text, $matches)) {
            return null;
        }

        $max = null;
        foreach ($matches[1] as $raw) {
            $amount = $this->parseAmountValue((string) $raw);
            if ($amount === null || $amount < 1.0) {
                continue;
            }
            // Skip obvious year / code-like values.
            if ($amount >= 1900 && $amount <= 2100 && fmod($amount, 1.0) < 0.001) {
                continue;
            }
            if ($max === null || $amount > $max) {
                $max = $amount;
            }
        }

        return $max;
    }

    /**
     * Finvalda / UAB "FINANSŲ VALDYMO SISTEMOS" PVM SF (SERIJA EFVS… NR.: …).
     *
     * @return array<string, string|float|null>
     */
    private function extractFinvaldaInvoice(string $text): array
    {
        $invoiceNumber = null;
        if (preg_match('/SERIJA\s+([A-Z0-9]+)\s+NR\.?\s*:?\s*(\d+)/iu', $text, $m)) {
            $invoiceNumber = $this->normalizeInvoiceNumber($m[1].'-'.$m[2]);
        }

        $invoiceDate = $this->normalizeDate($this->extractByRegex(
            $text,
            '/\bData\s*:\s*(\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/iu',
            1,
        ));

        $gross = $this->extractAmount($text, '/SUMA\s+APMOK[EĖ]JIMUI\s*:?\s*([\d\s.,]+)/iu');

        $net = null;
        $vat = null;
        if (preg_match('/PVM\s+21\s*%\s*:?\s*([\d\s.,]+)\s+([\d\s.,]+)/iu', $text, $taxLines)) {
            $net = $this->parseAmountValue($taxLines[1]);
            $vat = $this->parseAmountValue($taxLines[2]);
        }

        $net = $net ?? $this->extractAmount($text, '/VISO\s+BE\s+PVM\s*:?\s*([\d\s.,]+)/iu');

        if ($net === null && $gross !== null && $vat !== null) {
            $net = round(max(0, $gross - $vat), 2);
        }
        if ($vat === null && $net !== null && $gross !== null) {
            $vat = round(max(0, $gross - $net), 2);
        }
        if ($gross === null && $net !== null && $vat !== null) {
            $gross = round($net + $vat, 2);
        }

        // Seller block appears after the buyer (Futura) on these layouts.
        $sellerVat = $this->extractByRegex(
            $text,
            '/FINANS[UŲ]\s+VALDYMO\s+SISTEMOS[\s\S]{0,400}?PVM\s+mok[eė]tojo\s+kodas\s*:\s*(LT\d{8,12})/iu',
            1,
        );
        $sellerId = $this->extractByRegex(
            $text,
            '/FINANS[UŲ]\s+VALDYMO\s+SISTEMOS[\s\S]{0,400}?Kodas\s*:\s*([0-9]{7,11})/iu',
            1,
        );

        return array_filter([
            'provider_company_name' => 'Finansų valdymo sistemos, UAB',
            'provider_company_vat' => $sellerVat,
            'provider_company_id' => $sellerId,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'sum_without_vat' => $net,
            'vat' => $vat,
            'sum_inc_vat' => $gross,
            'currency' => 'EUR',
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Diversified Business Communications UK (divcom) exhibition invoices.
     *
     * @return array<string, string|float|null>
     */
    private function extractDivcomInvoice(string $text): array
    {
        $normalizedText = str_replace(['®', '©'], '', $text);

        $invoiceDate = $this->normalizeDate($this->extractByRegex(
            $normalizedText,
            '/Invoice\s*Date\s*:\s*([0-3]?\d[\/\.-][01]?\d[\/\.-](?:20)?\d{2})/i',
            1,
        ));

        // Number often sits on the next line after the label, or only in the filename.
        $invoiceNumber = null;
        if (preg_match('/Invoice\s*Number\s*:\s*([A-Z0-9][A-Z0-9\/\-]{3,40})/i', $normalizedText, $m)) {
            $invoiceNumber = $this->normalizeInvoiceNumber($m[1]);
        } elseif (preg_match('/Invoice\s*Number\s*:\s*\R\s*([A-Z0-9][A-Z0-9\/\-]{3,40})/i', $normalizedText, $m)) {
            $candidate = trim($m[1]);
            // Event titles like "Eurobus Expo 2026" are not invoice numbers.
            if (! preg_match('/\s/', $candidate) && preg_match('/\d{3,}/', $candidate) === 1) {
                $invoiceNumber = $this->normalizeInvoiceNumber($candidate);
            }
        }

        $subtotal = $this->extractAmount($normalizedText, '/\bSubtotal\b[^\d£]{0,20}£?\s*([\d\s\.,]+)/i');
        $total = $this->extractAmount($normalizedText, '/\bTotal\b[^\d£]{0,20}£?\s*([\d\s\.,]+)/i');
        $vat = $this->extractAmount($normalizedText, '/\bUK\s*VAT\s*\d+\s*%\s*£?\s*([\d\s\.,]+)/i');

        if ($vat === null && $subtotal !== null && $total !== null) {
            $vat = round(max(0, $total - $subtotal), 2);
        }
        if ($vat === null) {
            $vat = 0.0;
        }

        $vatCode = $this->extractByRegex($normalizedText, '/\b(GB\d{9}(?:\d{3})?)\b/', 1);
        $email = $this->extractByRegex($normalizedText, '/[A-Z0-9._%+\-]+@divcom\.co\.uk/i')
            ?? $this->extractByRegex($normalizedText, '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i');

        return array_filter([
            'provider_company_name' => 'Diversified Business Communications UK Limited',
            'provider_company_email' => $email,
            'provider_company_vat' => $vatCode,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'sum_without_vat' => $subtotal ?? $total,
            'vat' => $vat,
            'sum_inc_vat' => $total ?? $subtotal,
            'currency' => 'GBP',
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function looksLikeBankStatement(string $text): bool
    {
        $normalized = Str::lower($text);

        return Str::contains($normalized, [
            'sąskaitos išrašas',
            'saskaitos israsas',
            'account statement',
            'pradinis likutis',
            'galutinis likutis',
            'operacijos tipas',
        ]) && ! Str::contains($normalized, ['sąskaita faktūra', 'saskaita faktura', 'invoice number', 'invoice date']);
    }

    private function invoiceNumberFromFilename(string $filename): ?string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = preg_replace('/\s*\(\d+\)$/', '', $base) ?? $base;

        if (preg_match('/\b(INV[_-]?\d{6,})\b/i', $base, $m)) {
            return $this->normalizeInvoiceNumber($m[1]);
        }

        if (preg_match('/\b((?:EFVS|AR|SF|SP|NFM)[_-]?\d[\w\-]{2,})\b/i', $base, $m)) {
            return $this->normalizeInvoiceNumber($m[1]);
        }

        return null;
    }

    private function guessProviderNameExcludingOwn(string $text): ?string
    {
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/', $text) ?: [],
        )));

        foreach ($lines as $line) {
            if (! $this->isPlausibleCompanyName($line)) {
                continue;
            }
            if ($this->looksLikeOwnCompanyName($line)) {
                continue;
            }

            return Str::limit(trim($line, " \t\n\r\0\x0B:.-"), 255, '');
        }

        return null;
    }

    private function looksLikeOwnCompanyName(string $name): bool
    {
        $normalized = Str::lower(preg_replace('/[^a-z0-9]+/i', ' ', $name) ?? $name);

        return Str::contains($normalized, [
            'futura textiles',
            'futura textile',
            'uab futura',
        ]);
    }

    private function companyNameFromEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        $host = Str::lower((string) Str::after($email, '@'));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $base = (string) Str::before($host, '.');
        if ($base === '' || in_array($base, ['gmail', 'yahoo', 'hotmail', 'outlook', 'icloud'], true)) {
            return null;
        }

        $words = preg_split('/[\-_.]+/', $base) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== '' && ! in_array($w, ['info', 'mail', 'office', 'support', 'billing'], true)));
        if ($words === []) {
            return null;
        }

        return Str::limit(collect($words)->map(static fn (string $w): string => Str::title($w))->implode(' '), 255, '');
    }

    private function extractSellerVat(string $text): ?string
    {
        $sellerText = $this->textBeforeBuyer($text);

        return $this->extractByRegex($sellerText, '/(?:PVM\s+mok(?:[eė]tojo|\.)?\s*kodas|VAT(?:\s*(?:code|number|#|Registration))?)[:\s#\-]*([A-Z]{2}\d{8,12})/iu', 1)
            ?? $this->extractByRegex($sellerText, '/\bEU\s*VAT[:\s]*([A-Z]{2}[A-Z0-9]{8,12})/i', 1)
            ?? $this->extractByRegex($sellerText, '/\b(?:VAT|PVM)\b[:\s#\-]*([A-Z]{2}\d{8,12})/i', 1);
    }

    private function textBeforeBuyer(string $text): string
    {
        $parts = preg_split('/\b(?:Bill\s*to|Pirk[eė]jas|Customer)\b/iu', $text, 2);
        $seller = trim((string) ($parts[0] ?? ''));

        return $seller !== '' ? $seller : $text;
    }

    private function parseEnglishTextDate(string $raw): ?string
    {
        if (! preg_match(
            '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})\b/i',
            $raw,
            $m,
        )) {
            return null;
        }

        $months = [
            'january' => 1,
            'february' => 2,
            'march' => 3,
            'april' => 4,
            'may' => 5,
            'june' => 6,
            'july' => 7,
            'august' => 8,
            'september' => 9,
            'october' => 10,
            'november' => 11,
            'december' => 12,
        ];

        $month = $months[Str::lower($m[1])] ?? null;
        $day = (int) $m[2];
        $year = (int) $m[3];
        if ($month === null || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function detectProviderKey(string $text): ?string
    {
        $normalized = Str::lower($text);

        return match (true) {
            Str::contains($normalized, 'dsv road') => 'dsv',
            Str::contains($normalized, ['anthropic', 'antropic']) => 'anthropic',
            $this->looksLikeFteOwnSalesInvoice($normalized) => 'fte_own',
            Str::contains($normalized, 'telia') => 'telia',
            Str::contains($normalized, 'tele2') => 'tele2',
            Str::contains($normalized, 'bite') => 'bite',
            Str::contains($normalized, 'woocommerce ireland') => 'woocommerce',
            Str::contains($normalized, 'spiritus group') => 'spiritus',
            Str::contains($normalized, ['finansų valdymo sistemos', 'finansu valdymo sistemos', 'finvalda']) => 'finvalda',
            Str::contains($normalized, ['diversified business communications', 'diversi', 'divcom.co.uk']) => 'divcom',
            $this->looksLikeGermanRechnung($normalized) => 'german_rechnung',
            $this->looksLikeLithuanianPvmInvoice($normalized) => 'lt_pvm',
            default => null,
        };
    }

    /**
     * @param  list<string>  $lines
     */
    private function guessProviderName(array $lines): ?string
    {
        if ($lines === []) {
            return null;
        }

        $fromSeller = $this->nameNearLabels($lines, [
            'pardavėjas',
            'pardavejas',
            'tiekėjas',
            'tiekejas',
            'seller',
            'supplier',
            'vendor',
        ]);
        if ($fromSeller !== null) {
            return $fromSeller;
        }

        foreach ($lines as $line) {
            if ($this->isPlausibleCompanyName($line) && ! $this->looksLikeOwnCompanyName($line)) {
                return Str::limit(trim($line, " \t\n\r\0\x0B:.-"), 255, '');
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $labels
     */
    private function nameNearLabels(array $lines, array $labels): ?string
    {
        foreach ($lines as $index => $line) {
            $lower = Str::lower(ltrim($line, " \t-•*"));
            $matchedLabel = null;
            foreach ($labels as $label) {
                if (str_starts_with($lower, Str::lower($label))) {
                    $matchedLabel = $label;
                    break;
                }
            }
            if ($matchedLabel === null) {
                continue;
            }

            $remainder = trim((string) preg_replace('/'.preg_quote($matchedLabel, '/').'/iu', '', $line));
            foreach ($labels as $label) {
                $remainder = trim((string) preg_replace('/'.preg_quote($label, '/').'/iu', '', $remainder));
            }
            $remainder = trim($remainder, " \t\n\r\0\x0B:.-/");
            if ($this->isPlausibleCompanyName($remainder)) {
                return Str::limit($remainder, 255, '');
            }

            $last = min($index + 4, count($lines) - 1);
            for ($next = $index + 1; $next <= $last; $next++) {
                if ($this->isPlausibleCompanyName($lines[$next])) {
                    return Str::limit(trim($lines[$next], " \t\n\r\0\x0B:.-"), 255, '');
                }
            }
        }

        return null;
    }

    private function isPlausibleCompanyName(string $candidate): bool
    {
        $candidate = trim($candidate, " \t\n\r\0\x0B:.-/");
        if (mb_strlen($candidate) < 3 || mb_strlen($candidate) > 120) {
            return false;
        }
        if ($this->looksLikeOwnCompanyName($candidate)) {
            return false;
        }
        if (Str::contains(Str::lower($candidate), $this->providerNameSkipFragments())) {
            return false;
        }
        if (preg_match('/^\d+([.,]\d+)?%?$/', $candidate) === 1) {
            return false;
        }
        // Street / city address lines.
        if (preg_match('/\b(g\.|str\.|street|pr\.|avenue|road|ringuvos|liepkalnio)\b/iu', $candidate) === 1) {
            return false;
        }
        if (preg_match('/^\d{4,5}\b/', $candidate) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function providerNameSkipFragments(): array
    {
        return [
            'invoice', 'receipt', 'bill to', 'date', 'vat', '@', 'sąskaita', 'saskaita', 'pvm', 'page', 'tel.', 'www.',
            'pirkėjas', 'pirkejas', 'customer', 'buyer', 'seller', 'pardavėjas', 'pardavejas',
            'consignor', 'consignee', 'siuntėjas', 'siuntejas', 'gavėjas', 'gavejas', 'krovinio',
            'eilutės', 'pavadinimas', 'nuolaida', 'tarpinė', 'mokėtina', 'iš viso', 'lapas', 'siunt',
            'futura textiles', 'litauen', 'lithuania', 'reservierung', 'beschreibung',
            'rechnung', 'bruttobetrag', 'endsumme', 'mwst', 'mastercard', 'transaktions',
            'bank account', 'bank name', 'sort code', 'iban', 'swift', 'purchase order',
        ];
    }

    private function extractInvoiceNumber(string $text): ?string
    {
        if (preg_match('/\bNFM[=:\s\-]*(\d{3,12})/i', $text, $nfm)) {
            $fromNfm = $this->normalizeInvoiceNumber('NFM-'.$nfm[1]);
            if ($fromNfm !== null) {
                return $fromNfm;
            }
        }

        if (preg_match('/PVM\s+S[AĄ]SKAITA(?:[\s\-]*FAKT[UŪ]RA)?\s+Nr\.?\s*([A-Z]{1,8}[ \t\-]*\d{2,12})/iu', $text, $header)) {
            $fromHeader = $this->normalizeInvoiceNumber($header[1]);
            if ($fromHeader !== null) {
                return $fromHeader;
            }
        }

        $patterns = [
            '/\bRechnung\s*[:\t ]\s*([0-9]{3,12})\b/iu',
            '/s[aą]skaita(?:\s*fakt[uū]ra)?(?:\s*serija)?[^\n]{0,40}?(?:nr\.?|no\.?)[:\s]*([A-Z0-9][A-Z0-9\/\- \t]{2,30})/iu',
            '/(?:invoice\s*(?:no\.?|number)|s[aą]skaitos\s+(?:nr\.?|numeris))[:\s]*([A-Z0-9][A-Z0-9\/\- \t]{2,30})/iu',
            '/SERIJA\s+([A-Z0-9]+)\s+NR\.?\s*:?\s*(\d+)/iu',
            '/\b((?:NFM|SF|INV|SP)[=:\s\-]*[A-Z0-9]{3,20})\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            $raw = isset($matches[2])
                ? ((string) $matches[1]).'-'.((string) $matches[2])
                : (string) ($matches[1] ?? '');

            $value = $this->normalizeInvoiceNumber($raw);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeInvoiceNumber(?string $value): ?string
    {
        $value = strtoupper(preg_replace('/\s+/', '', str_replace('=', '-', trim((string) $value))) ?? '');
        $value = preg_replace('/^NR\.?/', '', $value) ?? $value;
        if (strlen($value) < 3 || strlen($value) > 34 || preg_match('/\d{2,}/', $value) !== 1) {
            return null;
        }
        if ($this->looksLikeIban($value)) {
            return null;
        }

        return Str::limit($value, 40, '');
    }

    private function looksLikeIban(string $value): bool
    {
        return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $value) === 1;
    }

    private function extractByRegex(string $text, string $pattern, int $group = 0): ?string
    {
        if (! preg_match($pattern, $text, $matches)) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim((string) ($matches[$group] ?? ''))) ?? '';

        return $value !== '' ? Str::limit($value, 255, '') : null;
    }

    private function normalizeDate(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
        $englishDate = $this->parseEnglishTextDate($raw);
        if ($englishDate !== null) {
            return $englishDate;
        }

        $lithuanianDate = $this->parseLithuanianTextDate($raw);
        if ($lithuanianDate !== null) {
            return $lithuanianDate;
        }

        $raw = str_replace('.', '/', str_replace('-', '/', $raw));
        $parts = explode('/', $raw);
        if (count($parts) !== 3) {
            return null;
        }

        if (strlen($parts[0]) === 4) {
            [$y, $m, $d] = $parts;
        } else {
            [$d, $m, $y] = $parts;
            if (strlen($y) === 2) {
                $y = '20'.$y;
            }
        }

        if (! checkdate((int) $m, (int) $d, (int) $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
    }

    private function parseLithuanianTextDate(string $raw): ?string
    {
        if (! preg_match('/(\d{4})\s*m\.\s*([a-ząčęėįšųūž]+)\s*mėn\.\s*(\d{1,2})\s*d\./iu', $raw, $m)) {
            return null;
        }

        $monthMap = [
            'sausio' => 1,
            'vasario' => 2,
            'kovo' => 3,
            'balandžio' => 4,
            'gegužės' => 5,
            'birželio' => 6,
            'liepos' => 7,
            'rugpjūčio' => 8,
            'rugsėjo' => 9,
            'spalio' => 10,
            'lapkričio' => 11,
            'gruodžio' => 12,
        ];

        $year = (int) $m[1];
        $monthName = Str::lower($m[2]);
        $day = (int) $m[3];
        $month = $monthMap[$monthName] ?? null;

        if ($month === null || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function extractAmount(string $text, string $pattern): ?float
    {
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
            return null;
        }

        foreach ($matches as $match) {
            $raw = (string) ($match[1] ?? '');
            $value = $this->parseAmountValue($raw);
            if ($this->isPlausibleMoney($raw, $value)) {
                return $value;
            }
        }

        return null;
    }

    private function isPlausibleMoney(string $raw, ?float $value): bool
    {
        if ($value === null || $value < 0 || $value > 999999.99) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $hasDecimal = str_contains($raw, '.') || str_contains($raw, ',');

        return $hasDecimal || strlen($digits) < 7;
    }

    private function parseAmountValue(string $raw): ?float
    {
        $raw = preg_replace('/[^\d,.\-]/', '', str_replace(' ', '', $raw)) ?? '';
        if ($raw === '' || $raw === '-' || $raw === '.' || $raw === ',') {
            return null;
        }

        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            if (strrpos($raw, ',') > strrpos($raw, '.')) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } else {
                $raw = str_replace(',', '', $raw);
            }
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return round((float) $raw, 2);
    }

    private function fileStartsWith(string $path, string $prefix): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $start = fread($handle, strlen($prefix));
        fclose($handle);

        return $start === $prefix;
    }

    private function repairExtractedText(string $text): string
    {
        $candidates = [$text];

        if (substr_count($text, "\0") >= 8) {
            $stripped = str_replace("\0", '', $text);
            $candidates[] = $stripped;
            $candidates[] = $this->shiftLatinBytes($stripped, 0x1D);
            $even = strlen($text) % 2 === 0 ? $text : $text."\0";
            $utf16 = @mb_convert_encoding($even, 'UTF-8', 'UTF-16LE');
            if (is_string($utf16) && $utf16 !== '') {
                $candidates[] = $utf16;
            }
        }

        $best = $text;
        $bestScore = $this->readableTextScore($text);
        foreach ($candidates as $candidate) {
            $score = $this->readableTextScore($candidate);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function shiftLatinBytes(string $text, int $delta): string
    {
        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $ord = ord($text[$i]);
            if ($ord === 10 || $ord === 13) {
                $out .= $text[$i];
            } elseif ($ord >= 0x01 && $ord <= 0x61) {
                $out .= chr($ord + $delta);
            } else {
                $out .= $text[$i];
            }
        }

        foreach (['ISO-8859-13', 'ISO-8859-1'] as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $out);
            if (is_string($converted) && $converted !== '' && $this->readableTextScore($converted) >= $this->readableTextScore($out)) {
                return $converted;
            }
        }

        return $out;
    }

    private function readableTextScore(string $text): int
    {
        $hay = Str::lower($text);
        $score = 0;
        foreach (['pvm', 'suma', 'kodas', 'uab', 'invoice', 'saskait', 'sąskait', 'eur', 'bank', 'moket', 'futura', 'pardav', 'pirk', 'serija'] as $needle) {
            if (str_contains($hay, $needle)) {
                $score++;
            }
        }

        return $score;
    }

    private function textLooksUnusable(string $text): bool
    {
        $text = trim($text);

        return $text === '' || $this->readableTextScore($text) < 3;
    }

    private function extractCurrency(string $text): ?string
    {
        if (preg_match('/\b(USD|GBP|PLN|CHF|SEK|NOK|DKK|CZK|JPY|CNY|CAD|AUD)\b/', $text, $match) === 1) {
            return strtoupper($match[1]);
        }

        if (preg_match('/€|\bEUR\b/u', $text) === 1) {
            return 'EUR';
        }

        if (preg_match('/\bUS\$|\$\s*\d/', $text) === 1) {
            return 'USD';
        }

        return null;
    }

    /**
     * Livewire JSON snapshots reject invalid UTF-8 from PDF/OCR text.
     */
    public static function sanitizeUtf8(string $text): string
    {
        $text = str_replace("\0", '', $text);

        $encoded = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        if (is_string($encoded)) {
            $decoded = json_decode($encoded);
            if (is_string($decoded)) {
                $text = $decoded;
            }
        }

        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        return is_string($stripped) ? $stripped : $text;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, string|float|null>
     */
    private function sanitizeParsed(array $parsed): array
    {
        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $parsed[$key] = self::sanitizeUtf8($value);
            }
        }

        if (isset($parsed['provider_company_name']) && is_string($parsed['provider_company_name'])) {
            $rawName = $parsed['provider_company_name'];
            if (LithuanianCompanyNameNormalizer::extract($rawName)['form'] !== null) {
                $normalized = LithuanianCompanyNameNormalizer::normalize($rawName);
                if ($normalized !== '') {
                    $parsed['provider_company_name'] = $normalized;
                }
            }
        }

        return $parsed;
    }
}
