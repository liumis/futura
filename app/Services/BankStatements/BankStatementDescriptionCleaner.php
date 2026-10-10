<?php

namespace App\Services\BankStatements;

/**
 * Cleans bank statement descriptions from Excel/journal noise.
 * Document/payment refs stay; counterpart/company names are dropped
 * (they belong in the Counterpart column). Useful remittance text is kept.
 */
class BankStatementDescriptionCleaner
{
    public static function clean(?string $description, ?string $counterpartName = null): ?string
    {
        if ($description === null) {
            return null;
        }

        $description = trim(preg_replace("/[ \t\f\v]+/u", ' ', str_replace(["\r\n", "\n", "\r"], ' ', $description)) ?? '');

        if ($description === '') {
            return null;
        }

        // SEB / LT PDF statement blobs: strip channel, bank, IBAN, and technical refs.
        $description = self::stripSebStatementNoise($description);
        if ($description === '') {
            return null;
        }

        // Drop leading journal codes:
        // BANKAS_IŠM · / BANKAS/IPL · / BANKAS IŠM · / BANKAS IPL · …
        while (preg_match('/^BANKAS(?:(?:[_\/]|\s+)[^\s·•]+)?(?:\s*[·•]\s*|\s+)/iu', $description) === 1) {
            $description = preg_replace('/^BANKAS(?:(?:[_\/]|\s+)[^\s·•]+)?(?:\s*[·•]\s*|\s+)/iu', '', $description, 1) ?? $description;
            $description = trim($description);
        }

        // Leftover direction token if BANKAS was already stripped earlier: "IŠM · …" / "IPL · …"
        while (preg_match('/^(?:I[ŠS]?M|I[PĮ]L|ISM|IPL|ĮPL|IŠM)(?:\s*[·•]\s*|\s+)/iu', $description) === 1) {
            $description = preg_replace('/^(?:I[ŠS]?M|I[PĮ]L|ISM|IPL|ĮPL|IŠM)(?:\s*[·•]\s*|\s+)/iu', '', $description, 1) ?? $description;
            $description = trim($description);
        }

        // Bare leading token without separator leftover.
        if (preg_match('/^(?:BANKAS(?:(?:[_\/]|\s+)[^\s·•]+)?|I[ŠS]?M|I[PĮ]L|ISM|IPL|ĮPL|IŠM)$/iu', $description) === 1) {
            return null;
        }

        // "DOC · remittance…"
        if (preg_match('/^([^\s·•]+)(?:\s*[·•]\s+)(.+)$/u', $description, $matches) === 1) {
            $document = trim($matches[1]);
            $rest = trim($matches[2]);

            $rest = self::stripLeadingPartyName($rest, $counterpartName);
            $rest = self::tidyRemittance($rest);
            $rest = self::stripTrailingDuplicateRef($rest, $document);
            $rest = self::stripTrailingIsoDates($rest);

            if ($rest === '' || strcasecmp($rest, $document) === 0 || self::isPartyNameOnly($rest)) {
                if ($document === '-') {
                    return null;
                }

                return self::finalize($document);
            }

            // Truncated Excel doc refs: "1004150,1004164,1003 · 1004150,1004164,1003946,1004110"
            if (self::isNumericRefList($document) && self::isNumericRefList($rest)) {
                return self::finalize(self::preferFullerNumericList($document, $rest));
            }

            return self::finalize($document.' · '.$rest);
        }

        // Whole cell: maybe "Company SERIJA FTE…" or a noisy blob.
        $stripped = self::stripLeadingPartyName($description, $counterpartName);
        $stripped = self::tidyRemittance($stripped);
        if ($stripped !== '' && strcasecmp($stripped, $description) !== 0) {
            return self::finalize($stripped);
        }

        if (self::isPartyNameOnly($description)) {
            return null;
        }

        return BankStatementCounterpartCleaner::clean($description) ?? self::finalize($description);
    }

    /**
     * Strip SEB PDF / OCR statement metadata, leaving remittance / invoice refs.
     */
    private static function stripSebStatementNoise(string $description): string
    {
        $text = trim($description);
        if ($text === '') {
            return '';
        }

        // Leading payment channel / operation type (OCR may use é/ė).
        $text = preg_replace(
            '/^(?:Mok[eėé]j\.?\s*banko\s+viduje|Tarpt\.?\s*mok[eėé]jimas|Europinis\s+mok[eėé]jimas|Komisinis\s+atlyginimas|SEPA\s+mok[eėé]jimas|Instant\s+mok[eėé]jimas)\s+/iu',
            '',
            $text,
        ) ?? $text;

        // Counterpart-name fragments that OCR puts on the remittance line.
        $text = preg_replace('/^MINISTERIJOS\b[, ]*/iu', '', $text) ?? $text;
        $text = preg_replace('/^ministerijos\b[, ]*/iu', '', $text) ?? $text;

        // Technical reference clauses (before IBAN/BIC so refs like RO… are not eaten).
        $text = preg_replace('/,?\s*dok\.?\s*nr\.?\s*[A-Z0-9\/\-]+/iu', ' ', $text) ?? $text;
        $text = preg_replace('/,?\s*unik\.?\s*mok[eėé]j\.?\s*kodas\s*[A-Z0-9\/\-]+/iu', ' ', $text) ?? $text;
        $text = preg_replace('/,?\s*mok\.?\s*ident\.?\s*kodas\s*[A-Z0-9\/\-]+/iu', ' ', $text) ?? $text;
        // gav. ident[.|,] kodas … (OCR often uses a comma; may include "IV pažymos nr.…")
        $text = preg_replace('/,?\s*gav\.?\s*ident[.|,]?\s*kodas\s+.*?(?=,?\s*operacijos|\s*$)/iu', ' ', $text) ?? $text;
        $text = preg_replace('/,?\s*galut\.?\s*gav(?:\.|\s+ident[.|,]?\s*kodas)?\s*.*?(?=,?\s*operacijos|,?\s*galut\.|\s*$)/iu', ' ', $text) ?? $text;
        $text = preg_replace('/,?\s*galut\.?\s*$/iu', ' ', $text) ?? $text;
        $text = preg_replace('/,?\s*operacijos\s+nr\.?\s*[A-Z0-9]+/iu', ' ', $text) ?? $text;
        $text = preg_replace('/,?\s*data\s+\d{1,2}\/\d{1,2}\/\d{2,4}/iu', ' ', $text) ?? $text;
        $text = preg_replace('/,?\s*suma\s+[\d.,]+\s*Eur\.?/iu', ' ', $text) ?? $text;
        // Europinis fee notes: "…, nr.347," (comma-prefixed; do not touch "ITC Nr.002240").
        $text = preg_replace('/,\s*nr\.?\s*\d{1,6}(?=,|\s|$)/iu', ' ', $text) ?? $text;

        // BIC + bank name blocks.
        $text = preg_replace('/\b[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/\bAB\s+SEB\s+BANKAS\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bSEB\s+bankas\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bLUMINOR\s+BANK\s+AS(?:\s+LITHUANIAN\s+BRANCH)?\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bNORDEA\s+BANK\s+ABP\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bAS\s+CITADELE\s+BANKA(?:\s+LIETUVOS\s+FILIALAS)?\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bAS\s+BANKA\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bSWEDBANK\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bSPARKASSE(?:\s+[A-Z\-]+)*\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bIDBI\s+BANK\s+LIMITED\b/iu', ' ', $text) ?? $text;

        // Real IBANs only (exact country lengths — avoid eating bank refs like RO238…).
        $text = preg_replace(
            '/\b(?:LT\d{18}|DE\d{20}|FI\d{16}|SE\d{22}|LV\d{19}|EE\d{18}|PL\d{24}|NL\d{16}|BE\d{14}|FR\d{25}|GB\d{18}|IE\d{20}|AT\d{18}|IT\d{25}|ES\d{22}|DK\d{16}|NO\d{13}|CZ\d{22}|SK\d{22}|HU\d{26}|CH\d{19})\b/u',
            ' ',
            $text,
        ) ?? $text;

        // PDF footer / filename leftovers from OCR.
        $text = preg_replace('/\b\S+\.pdf\.?\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bindelininkui[^\s,]*/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bInformuojame\b.*$/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bPuslapis\s+\d+\s*\(\d+\).*$/iu', ' ', $text) ?? $text;

        // Leading commas / leftover punctuation from stripped clauses (keep lone "-").
        $text = preg_replace('/^\s*,\s*/u', '', $text) ?? $text;
        $text = preg_replace('/\s*,\s*$/u', '', $text) ?? $text;
        $text = preg_replace('/\s*,\s*,+/u', ',', $text) ?? $text;
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;
        $text = trim($text, " \t,");
        $text = preg_replace('/\s+,\s+/u', ', ', $text) ?? $text;

        return rtrim($text, '.-');
    }

    /**
     * Remove a leading company/person name, keeping invoice/remittance text.
     * e.g. "Ariel italia Srls SERIJA FTE NR.: 0112" → "SERIJA FTE NR.: 0112"
     */
    private static function stripLeadingPartyName(string $text, ?string $counterpartName = null): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $counterpart = trim((string) (BankStatementCounterpartCleaner::clean($counterpartName) ?? $counterpartName ?? ''));
        if ($counterpart !== '' && ! self::isPurposeOrFeeNote($counterpart)) {
            $counterpartCompany = self::companyPrefixFromMixed($counterpart) ?? $counterpart;
            // Only strip when the counterpart looks like a party name, not a full purpose note.
            if ($counterpartCompany !== ''
                && self::looksLikePartyPrefix($counterpartCompany)
                && preg_match('/^'.preg_quote($counterpartCompany, '/').'(?:\s+|$)/iu', $text) === 1) {
                $text = trim(preg_replace('/^'.preg_quote($counterpartCompany, '/').'\s*/iu', '', $text) ?? $text);
                if ($text === '') {
                    return '';
                }
            }
        }

        // Quoted legal entity: UAB "Vinilas" …
        if (preg_match('/^(?:UAB|AB|MB|VšĮ|SIA)\s+"[^"]+"\s+(.+)$/iu', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        // Unquoted legal entity + remittance marker / code.
        if (preg_match('/^(?:UAB|AB|MB|VšĮ|SIA)\s+[\p{L}0-9"\'.&\/\-]+\s+(.+)$/iu', $text, $matches) === 1
            && self::hasRemittanceMarker($matches[1])) {
            return trim($matches[1]);
        }

        // "Company LegalForm remittance…" (Srls, SRL, KFT, d.o.o., LTD, …)
        if (preg_match(
            '/^(.+?\b(?:Srls|SRL|KFT|LDA|Ltd|Limited|GmbH|PBC|OU|A\/S|S\.A\.|s\.r\.o\.|d\.o\.o\.|Bros|m\.b\.H\.))\s+(.+)$/iu',
            $text,
            $matches
        ) === 1) {
            $rest = trim($matches[2]);
            if ($rest !== '' && (self::hasRemittanceMarker($rest) || preg_match('/^[A-Z0-9][A-Z0-9\/\-.,:\s]{2,}$/i', $rest) === 1)) {
                return $rest;
            }
        }

        // Multi-word name before a clear remittance marker.
        if (preg_match('/^(.+?)\s+((?:SERIJA\s+FTE|FTE\s*\d|FTE\d|INV\d|INV[- ]?\d|FAKTURA|INVOICE|PROFORMA|Vs\s*Rif\.?|NR\.?\s*:|Payment for|Apmok[eė]jimas|Prepayment|Rif\.?).+)$/iu', $text, $matches) === 1) {
            $leading = trim($matches[1]);
            $rest = trim($matches[2]);
            if (self::looksLikePartyPrefix($leading)) {
                return $rest;
            }
        }

        // Person / company name before a purpose note (salary, allowance, …).
        if (preg_match('/^((?:\p{L}+[\s\-]){1,4}\p{L}+)\s+(.+)$/u', $text, $matches) === 1) {
            $leading = trim($matches[1]);
            $rest = trim($matches[2]);
            if (self::looksLikePartyPrefix($leading) && self::isPurposeOrFeeNote($rest)) {
                return $rest;
            }
        }

        return $text;
    }

    private static function tidyRemittance(string $text): string
    {
        $text = trim($text);

        // Leftover card-payment blob after merchant name was stripped.
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}\s+\d{1,2}:\d{2}\b/u', $text) === 1) {
            return '';
        }

        $text = preg_replace('/\s*\((https?:\/\/[^)]+)\)/iu', '', $text) ?? $text;
        $text = preg_replace('/\s*https?:\/\/\S+/iu', '', $text) ?? $text;
        $text = preg_replace('/\s*Užs:\s*\S+/iu', '', $text) ?? $text;
        $text = preg_replace('/\s*\(P\d+\)/iu', '', $text) ?? $text;
        $text = preg_replace('/\bR\d{6,}\b/u', '', $text) ?? $text;
        $text = preg_replace('/\s+-\s*$/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private static function companyPrefixFromMixed(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^((?:UAB|AB|MB|VšĮ|SIA)\s+"[^"]+")\b/iu', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match(
            '/^(.+?\b(?:Srls|SRL|KFT|LDA|Ltd|Limited|GmbH|PBC|OU|A\/S|S\.A\.|s\.r\.o\.|d\.o\.o\.|Bros|m\.b\.H\.))\b/iu',
            $text,
            $matches
        ) === 1) {
            $prefix = trim($matches[1]);
            if (strcasecmp($prefix, $text) !== 0) {
                return $prefix;
            }
        }

        return null;
    }

    private static function hasRemittanceMarker(string $text): bool
    {
        return preg_match(
            '/\b(SERIJA\s+FTE|FTE\s*\d|FTE\d|INV\d|INV[- ]?\d|FAKTURA|INVOICE|PROFORMA|Vs\s*Rif|NR\.?\s*:|Payment for|Apmok[eė]jimas|Prepayment|Rif\.?|PEDROTAVARES|refund|gr[aą]žinim)/iu',
            $text
        ) === 1
            || preg_match('/\d{3,}/', $text) === 1;
    }

    private static function isNumericRefList(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && preg_match('/^\d+(?:\s*,\s*\d+)*$/', $value) === 1;
    }

    private static function preferFullerNumericList(string $left, string $right): string
    {
        $leftParts = preg_split('/\s*,\s*/', trim($left)) ?: [];
        $rightParts = preg_split('/\s*,\s*/', trim($right)) ?: [];

        // Prefer the list whose tokens are not truncated prefixes of the other.
        $leftJoined = implode(',', $leftParts);
        $rightJoined = implode(',', $rightParts);

        if (count($rightParts) >= count($leftParts) && mb_strlen($rightJoined) >= mb_strlen($leftJoined)) {
            return $rightJoined;
        }

        return $leftJoined !== '' ? $leftJoined : $rightJoined;
    }

    private static function isPurposeOrFeeNote(string $text): bool
    {
        return preg_match('/\b(atsiskaitymas|apmokėjimas|apmokejimas|permoka|faktura|invoice|serija|prepayment|payment for|bank fee|mokestis|mokesčiai|mokesciai|administruojami|tarp savo|tarp s[aą]vo|perk[ėe]limas|between account|between your|refund|gr[aą]žinimas|pavedimas|pervedim\w*|paslaug\w*|kortel[eė]s naudojimo|l[eė]š[uų] gr[aą]žinimas|atlyginimas|salary|dienpinigiai|daily allowance|valstybin[eėé]|sodra|pav|grupin\w*\s+pinig|i kita bank|^PVM$|^GPM$|\b1001\b|\b1311\b|\b252\b)/iu', $text) === 1;
    }

    /**
     * True when the whole counterpart/G cell is a purpose note (salary, own transfer, fee, …)
     * rather than a company name with trailing remittance.
     */
    public static function isPurposeRemittanceCell(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return false;
        }

        if (self::isPurposeOrFeeNote($text)) {
            return true;
        }

        return BankStatementCounterpartResolver::isRemittanceOnly($text)
            || BankStatementDirectionResolver::isOwnTransfer(null, null, $text, $text)
            || BankStatementDirectionResolver::isBankServiceFee($text, $text)
            || BankStatementDirectionResolver::isGenericOwnTransferLabel($text);
    }

    private static function looksLikePartyPrefix(string $leading): bool
    {
        if (self::isPurposeOrFeeNote($leading)) {
            return false;
        }

        if (self::isPartyNameOnly($leading)) {
            return true;
        }

        if (preg_match('/\b(UAB|AB|MB|VšĮ|SIA|OU|A\/S|Ltd|Limited|Inc\.?|GmbH|PBC|KFT|LDA|Srls|SRL|S\.A\.|s\.r\.o\.|d\.o\.o\.|Bros|m\.b\.H\.)\b/iu', $leading) === 1) {
            return true;
        }

        return preg_match('/^[\p{L}][\p{L}\s"\'.,&\-\/]+$/u', $leading) === 1
            && preg_match('/\d{4,}/', $leading) !== 1
            && ! self::hasRemittanceMarker($leading);
    }

    private static function isPartyNameOnly(string $party): bool
    {
        $party = trim(preg_replace('/\s+/u', ' ', $party) ?? '');

        if ($party === '') {
            return true;
        }

        if (self::isPurposeOrFeeNote($party)) {
            return false;
        }

        if (preg_match('/\b(FTE\s*\d+|FTE\d+|INV[- ]?\d+|FAKTURA|INVOICE\s*N|SERIJA\s*FTE|NR\.?\s*:|PROFORMA|Vs\s*Rif)/iu', $party) === 1) {
            return false;
        }

        // Invoice / order style numbers still present (ISO dates already stripped).
        if (preg_match('/\d{4,}/', $party) === 1) {
            return false;
        }

        if (preg_match('/\b(UAB|AB|MB|VšĮ|SIA|OU|A\/S|Ltd|Limited|Inc\.?|GmbH|PBC|KFT|LDA|Srls|SRL|S\.A\.|s\.r\.o\.|d\.o\.o\.|Bros|m\.b\.H\.)\b/iu', $party) === 1) {
            return true;
        }

        // Multi-word person / free-text company name without digits.
        if (preg_match('/^[\p{L}][\p{L}\s"\'.,&\-\/]+$/u', $party) === 1) {
            $words = preg_split('/\s+/u', $party) ?: [];

            return count($words) >= 2;
        }

        return false;
    }

    private static function stripTrailingIsoDates(string $party): string
    {
        $party = preg_replace('/(?:\s+\d{4}-\d{2}-\d{2})+\s*$/u', '', $party) ?? $party;

        return trim($party);
    }

    private static function stripTrailingDuplicateRef(string $party, string $document): string
    {
        $document = trim($document);
        if ($document === '') {
            return trim($party);
        }

        $party = preg_replace('/\s+'.preg_quote($document, '/').'\s*$/iu', '', $party) ?? $party;

        return trim($party);
    }

    private static function finalize(string $description): ?string
    {
        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? '');

        return $description !== '' ? $description : null;
    }
}
