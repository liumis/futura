<?php

namespace App\Services\BankStatements;

use App\Enums\BankStatementDirection;
use App\Exceptions\BankStatementXmlException;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class BankStatementXmlParser
{
    /**
     * @var array<string, string>
     */
    private const BIC_BANKS = [
        'HABALT' => 'Swedbank',
        'CBVILT' => 'SEB',
        'AGBLLT' => 'Luminor',
        'INDULT' => 'Šiaulių bankas',
        'MDBALT' => 'Medicinos bankas',
        'CBSBLT' => 'Citadele',
        'UNLALT' => 'Urbo bankas',
        'REVOLT' => 'Revolut',
        'EPUHLT' => 'Paysera',
        'LIMELT' => 'Lietuvos bankas',
    ];

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
    public function parse(string $xml): array
    {
        $contents = $this->normalizeXml($xml);

        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($contents, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null) {
            throw new BankStatementXmlException('The file is not a valid bank statement XML.');
        }

        $xpath = new DOMXPath($document);
        $statements = $xpath->query('//*[local-name()="Stmt" or local-name()="Rpt"]');

        if ($statements === false || $statements->length === 0) {
            throw new BankStatementXmlException('No bank statement entries were found in the XML (expected camt.053 or camt.052).');
        }

        $messageId = $this->firstText($xpath, $document->documentElement, ['.//*[local-name()="MsgId"]']);
        $merged = [
            'message_id' => $messageId,
            'statement_id' => null,
            'account_iban' => null,
            'bank_name' => null,
            'currency' => null,
            'period_from' => null,
            'period_to' => null,
            'transactions' => [],
        ];

        foreach ($statements as $statement) {
            if (! $statement instanceof DOMElement) {
                continue;
            }

            $parsed = $this->parseStatement($xpath, $statement);
            $merged['statement_id'] ??= $parsed['statement_id'];
            $merged['account_iban'] ??= $parsed['account_iban'];
            $merged['bank_name'] ??= $parsed['bank_name'];
            $merged['currency'] ??= $parsed['currency'];
            $merged['period_from'] = $this->minDate($merged['period_from'], $parsed['period_from']);
            $merged['period_to'] = $this->maxDate($merged['period_to'], $parsed['period_to']);
            $merged['transactions'] = [...$merged['transactions'], ...$parsed['transactions']];
        }

        if ($merged['transactions'] === []) {
            throw new BankStatementXmlException('The bank statement XML does not contain any income or outcome entries.');
        }

        return $merged;
    }

    /**
     * @return array{
     *     statement_id: ?string,
     *     account_iban: ?string,
     *     bank_name: ?string,
     *     currency: ?string,
     *     period_from: ?string,
     *     period_to: ?string,
     *     transactions: list<array<string, mixed>>
     * }
     */
    private function parseStatement(DOMXPath $xpath, DOMElement $statement): array
    {
        $iban = $this->normalizeIban($this->firstText($xpath, $statement, [
            './*[local-name()="Acct"]//*[local-name()="IBAN"]',
            './/*[local-name()="Acct"]//*[local-name()="IBAN"]',
        ]));
        $currency = $this->firstText($xpath, $statement, [
            './*[local-name()="Acct"]/*[local-name()="Ccy"]',
            './/*[local-name()="Acct"]/*[local-name()="Ccy"]',
        ]);
        $bic = $this->firstText($xpath, $statement, [
            './*[local-name()="Acct"]//*[local-name()="BIC"]',
            './*[local-name()="Acct"]//*[local-name()="BICFI"]',
            './/*[local-name()="Acct"]//*[local-name()="BIC"]',
            './/*[local-name()="Acct"]//*[local-name()="BICFI"]',
        ]);
        $bankName = $this->firstText($xpath, $statement, [
            './*[local-name()="Acct"]/*[local-name()="Svcr"]//*[local-name()="Nm"]',
            './/*[local-name()="Acct"]/*[local-name()="Svcr"]//*[local-name()="Nm"]',
        ]) ?: $this->bankNameFromBic($bic);

        $periodFrom = $this->firstDate($xpath, $statement, [
            './*[local-name()="FrToDt"]/*[local-name()="FrDtTm"]',
            './/*[local-name()="FrToDt"]/*[local-name()="FrDtTm"]',
        ]);
        $periodTo = $this->firstDate($xpath, $statement, [
            './*[local-name()="FrToDt"]/*[local-name()="ToDtTm"]',
            './/*[local-name()="FrToDt"]/*[local-name()="ToDtTm"]',
        ]);

        $transactions = [];

        foreach ($xpath->query('./*[local-name()="Ntry"] | ./*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]', $statement) ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->localName === 'TxDtls') {
                continue;
            }

            foreach ($this->parseEntry($xpath, $node, $iban, $bankName, $currency) as $transaction) {
                $transactions[] = $transaction;
            }
        }

        return [
            'statement_id' => $this->firstText($xpath, $statement, ['./*[local-name()="Id"]']),
            'account_iban' => $iban,
            'bank_name' => $bankName,
            'currency' => $currency,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'transactions' => $transactions,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseEntry(
        DOMXPath $xpath,
        DOMElement $entry,
        ?string $accountIban,
        ?string $bankName,
        ?string $fallbackCurrency,
    ): array {
        $details = [];
        foreach ($xpath->query('.//*[local-name()="TxDtls"]', $entry) ?: [] as $detail) {
            if ($detail instanceof DOMElement) {
                $details[] = $detail;
            }
        }

        $sources = $details !== [] ? $details : [$entry];
        $transactions = [];

        foreach ($sources as $source) {
            $amountNode = $this->firstElement($xpath, $source, [
                './*[local-name()="Amt"]',
                './*[local-name()="AmtDtls"]/*[local-name()="TxAmt"]/*[local-name()="Amt"]',
                './*[local-name()="AmtDtls"]/*[local-name()="InstdAmt"]/*[local-name()="Amt"]',
            ]) ?? $this->firstElement($xpath, $entry, ['./*[local-name()="Amt"]']);

            $amount = $this->parseAmount($amountNode?->textContent ?? null);
            if ($amount === null || $amount <= 0) {
                continue;
            }

            $directionCode = strtoupper((string) (
                $this->firstText($xpath, $source, ['./*[local-name()="CdtDbtInd"]'])
                ?? $this->firstText($xpath, $entry, ['./*[local-name()="CdtDbtInd"]'])
                ?? ''
            ));

            $direction = match ($directionCode) {
                'CRDT' => BankStatementDirection::Credit,
                'DBIT' => BankStatementDirection::Debit,
                default => null,
            };

            if ($direction === null) {
                continue;
            }

            $currency = strtoupper((string) (
                $amountNode?->getAttribute('Ccy')
                ?: $this->firstText($xpath, $source, ['./*[local-name()="Amt"]/@Ccy'])
                ?: $fallbackCurrency
                ?: 'EUR'
            ));

            $bookingDate = $this->firstDate($xpath, $entry, [
                './*[local-name()="BookgDt"]/*[local-name()="Dt"]',
                './*[local-name()="BookgDt"]/*[local-name()="DtTm"]',
                './/*[local-name()="BookgDt"]/*[local-name()="Dt"]',
                './/*[local-name()="BookgDt"]/*[local-name()="DtTm"]',
            ]) ?? $this->firstDate($xpath, $source, [
                './*[local-name()="BookgDt"]/*[local-name()="Dt"]',
                './*[local-name()="BookgDt"]/*[local-name()="DtTm"]',
            ]);

            if ($bookingDate === null) {
                continue;
            }

            $valueDate = $this->firstDate($xpath, $entry, [
                './*[local-name()="ValDt"]/*[local-name()="Dt"]',
                './*[local-name()="ValDt"]/*[local-name()="DtTm"]',
            ]);

            $isIncome = $direction === BankStatementDirection::Credit;
            $counterpartName = $this->firstText($xpath, $source, $isIncome
                ? ['.//*[local-name()="Dbtr"]/*[local-name()="Nm"]', './/*[local-name()="UltmtDbtr"]/*[local-name()="Nm"]', './/*[local-name()="Cdtr"]/*[local-name()="Nm"]']
                : ['.//*[local-name()="Cdtr"]/*[local-name()="Nm"]', './/*[local-name()="UltmtCdtr"]/*[local-name()="Nm"]', './/*[local-name()="Dbtr"]/*[local-name()="Nm"]']);
            $counterpartIban = $this->normalizeIban($this->firstText($xpath, $source, $isIncome
                ? ['.//*[local-name()="DbtrAcct"]//*[local-name()="IBAN"]', './/*[local-name()="CdtrAcct"]//*[local-name()="IBAN"]']
                : ['.//*[local-name()="CdtrAcct"]//*[local-name()="IBAN"]', './/*[local-name()="DbtrAcct"]//*[local-name()="IBAN"]']));
            $counterpartBic = $this->firstText($xpath, $source, $isIncome
                ? ['.//*[local-name()="DbtrAgt"]//*[local-name()="BIC"]', './/*[local-name()="DbtrAgt"]//*[local-name()="BICFI"]']
                : ['.//*[local-name()="CdtrAgt"]//*[local-name()="BIC"]', './/*[local-name()="CdtrAgt"]//*[local-name()="BICFI"]']);

            $descriptionParts = [];
            foreach ($xpath->query('.//*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $source) ?: [] as $remittance) {
                $text = trim($remittance->textContent);
                if ($text !== '') {
                    $descriptionParts[] = $text;
                }
            }
            $additionalInfo = $this->firstText($xpath, $entry, ['./*[local-name()="AddtlNtryInf"]']);
            if (filled($additionalInfo)) {
                $descriptionParts[] = $additionalInfo;
            }

            $transactions[] = [
                'booking_date' => $bookingDate,
                'value_date' => $valueDate,
                'direction' => $direction,
                'amount' => $amount,
                'currency' => $currency !== '' ? $currency : 'EUR',
                'account_iban' => $accountIban,
                'bank_name' => $bankName,
                'counterpart_name' => $counterpartName,
                'counterpart_iban' => $counterpartIban,
                'counterpart_bic' => $counterpartBic,
                'description' => $descriptionParts === [] ? null : implode(' ', $descriptionParts),
                'end_to_end_id' => $this->firstText($xpath, $source, ['.//*[local-name()="EndToEndId"]']),
                'account_servicer_ref' => $this->firstText($xpath, $entry, ['./*[local-name()="AcctSvcrRef"]'])
                    ?? $this->firstText($xpath, $source, ['.//*[local-name()="AcctSvcrRef"]']),
                'transaction_id' => $this->firstText($xpath, $source, ['.//*[local-name()="TxId"]'])
                    ?? $this->firstText($xpath, $entry, ['./*[local-name()="NtryRef"]']),
            ];
        }

        return $transactions;
    }

    private function normalizeXml(string $xml): string
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', ltrim($xml)) ?? $xml;

        if ($contents === '') {
            throw new BankStatementXmlException('The uploaded file is empty.');
        }

        return $contents;
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstText(DOMXPath $xpath, DOMNode $context, array $queries): ?string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query, $context);
            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $value = trim($nodes->item(0)?->textContent ?? '');
            if ($value !== '' && $value !== 'NOTPROVIDED') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstElement(DOMXPath $xpath, DOMNode $context, array $queries): ?DOMElement
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query, $context);
            $node = $nodes !== false ? $nodes->item(0) : null;
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstDate(DOMXPath $xpath, DOMNode $context, array $queries): ?string
    {
        $value = $this->firstText($xpath, $context, $queries);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseAmount(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if (! is_numeric($normalized)) {
            return null;
        }

        return round(abs((float) $normalized), 2);
    }

    private function normalizeIban(?string $iban): ?string
    {
        if ($iban === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/\s+/', '', $iban) ?? $iban);

        return $normalized !== '' ? $normalized : null;
    }

    private function bankNameFromBic(?string $bic): ?string
    {
        if ($bic === null || strlen($bic) < 6) {
            return null;
        }

        $prefix = strtoupper(substr($bic, 0, 6));

        return self::BIC_BANKS[$prefix] ?? $bic;
    }

    private function minDate(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }

        if ($current === null || $candidate < $current) {
            return $candidate;
        }

        return $current;
    }

    private function maxDate(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }

        if ($current === null || $candidate > $current) {
            return $candidate;
        }

        return $current;
    }
}
