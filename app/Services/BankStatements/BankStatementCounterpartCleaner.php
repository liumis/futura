<?php

namespace App\Services\BankStatements;

use App\Services\LithuanianCompanyNameNormalizer;

/**
 * Extracts a usable counterpart/company name from noisy bank export text
 * (especially Excel cash-flow column G card-payment blobs).
 */
class BankStatementCounterpartCleaner
{
    public static function clean(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim(preg_replace("/[ \t\f\v]+/u", ' ', str_replace(["\r\n", "\n", "\r"], ' ', $name)) ?? '');

        if ($name === '') {
            return null;
        }

        // Card payments: "Merchant Name 30/07/2026 10:05 kortelė..."
        if (preg_match('/^(.+?)\s+\d{2}\/\d{2}\/\d{4}\s+\d{1,2}:\d{2}\b/u', $name, $matches) === 1) {
            return self::finalize($matches[1]);
        }

        // Card fee lines: "Kortelės..**3381 Kortelės naudojimo mokestis (2026/07)"
        if (preg_match('/^Kortel[ėe]s?\.\.[^\s]*\s+(.+)$/iu', $name, $matches) === 1) {
            $fee = preg_replace('/\s*\(\d{4}\/\d{1,2}\)\s*$/u', '', $matches[1]) ?? $matches[1];

            return self::finalize($fee);
        }

        $cleaned = $name;

        // Drop URLs (parenthesized or bare).
        $cleaned = preg_replace('/\s*\((https?:\/\/[^)]+)\)/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*https?:\/\/\S+/iu', '', $cleaned) ?? $cleaned;

        // Drop order / payment reference tokens.
        $cleaned = preg_replace('/\s*Užs:\s*\S+/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s*\(P\d+\)/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+R\d{6,}\b.*$/u', '', $cleaned) ?? $cleaned;

        // Trailing invoice numbers jammed onto the name:
        // "Diversified UK & Europe INV2018888143 ,INV201888144"
        $cleaned = preg_replace(
            '/\s+INV[-_]?\d{5,}(?:\s*,\s*INV?[-_]?\d{5,})*\s*$/iu',
            '',
            $cleaned,
        ) ?? $cleaned;

        // Trailing settlement words.
        $cleaned = preg_replace('/\s+(apmokėjimas|apmokejimas|atsiskaitymas)\s*$/iu', '', $cleaned) ?? $cleaned;

        // Company mixed with remittance: "Ariel italia Srls SERIJA FTE NR.: 0112"
        $companyOnly = self::stripTrailingRemittance($cleaned);
        if ($companyOnly !== null) {
            $cleaned = $companyOnly;
        }

        // Quoted organization followed by a trailing code: ..."GAMTOS ATEITIS" GIA051035
        if (preg_match('/^(.+"[^"]+")\s+[A-Z0-9]{5,}\s*$/u', $cleaned, $matches) === 1) {
            $cleaned = $matches[1];
        }

        return self::finalize($cleaned);
    }

    /**
     * Remittance / purpose text that Excel put in the counterpart column after the company name.
     * Example: UAB" Finansų valdymo sistemos"  Atsiskaitymas → "Atsiskaitymas"
     */
    public static function extractTrailingRemittance(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim(preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\n", "\r"], ' ', $raw)) ?? '');
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(.*?)\s+(apmokėjimas|apmokejimas|atsiskaitymas|permoka)\s*$/iu', $raw, $matches) === 1) {
            return trim($matches[2]);
        }

        // VMI / Sodra: tax type, payment code, or purpose after the authority name.
        if (preg_match(
            '/^(?:VALSTYBIN[ĖE]\s+MOKES[ČC]I[UŲ]\s+INSPEKCIJA|VMI|SODRA|VSDFV(?:\s+\p{L}+)*)\s+(.+)$/iu',
            $raw,
            $matches
        ) === 1) {
            $rest = trim($matches[1]);
            if ($rest === '') {
                return null;
            }
            if (preg_match('/^(PVM|GPM|SODRA|1001|1311|252|\d{3,4})$/iu', $rest) === 1) {
                return $rest;
            }
            if (preg_match('/administruojami/iu', $rest) === 1) {
                return 'administruojami mokesčiai';
            }

            return $rest;
        }

        if (preg_match(
            '/^(.+?\b(?:Srls|SRL|KFT|LDA|Ltd|Limited|GmbH|PBC|OU|A\/S|S\.A\.|s\.r\.o\.|d\.o\.o\.|Bros|m\.b\.H\.))\s+(.+)$/iu',
            $raw,
            $matches
        ) === 1) {
            $rest = trim($matches[2]);
            // Legal-form continuation, not remittance: "GmbH & Co. KG 10046874"
            if ($rest !== '' && preg_match('/^&?\s*Co\.?\s*KG\b/iu', $rest) === 1) {
                $afterCo = trim(preg_replace('/^&?\s*Co\.?\s*KG\b\.?/iu', '', $rest) ?? '');
                if ($afterCo === '' || preg_match('/^\d{3,}$/', $afterCo) === 1) {
                    return null;
                }
                $rest = $afterCo;
            }
            if ($rest !== '' && preg_match('/\b(SERIJA|FTE|INV|FAKTURA|INVOICE|PROFORMA|NR\.?\s*:|Payment|Rif\.?|PEDROTAVARES|EFVS|\d{3,})/iu', $rest) === 1) {
                return $rest;
            }
        }

        if (preg_match('/^(?:UAB|AB|MB|VšĮ|SIA)\s*"?\s*[^"]+"\s+(.+)$/iu', $raw, $matches) === 1) {
            $rest = trim($matches[1]);

            return $rest !== '' ? $rest : null;
        }

        return null;
    }

    /**
     * Keep company name when remittance text is appended after a legal form.
     */
    private static function stripTrailingRemittance(string $name): ?string
    {
        if (preg_match(
            '/^(.+?\b(?:Srls|SRL|KFT|LDA|Ltd|Limited|GmbH|PBC|OU|A\/S|S\.A\.|s\.r\.o\.|d\.o\.o\.|Bros|m\.b\.H\.))\s+(.+)$/iu',
            $name,
            $matches
        ) === 1) {
            $rest = trim($matches[2]);
            if ($rest !== '' && preg_match('/\b(SERIJA|FTE|INV|FAKTURA|INVOICE|PROFORMA|NR\.?\s*:|Payment|Rif\.?|PEDROTAVARES|\d{3,})/iu', $rest) === 1) {
                return trim($matches[1]);
            }
        }

        if (preg_match('/^((?:UAB|AB|MB|VšĮ|SIA)\s+"[^"]+")\s+(.+)$/iu', $name, $matches) === 1) {
            return trim($matches[1]);
        }

        // Drop VMI boilerplate after the authority name (keep short "VALSTYBINĖ MOKESČIŲ INSPEKCIJA").
        if (preg_match(
            '/^(VALSTYBIN[ĖE]\s+MOKES[ČC]I[UŲ]\s+INSPEKCIJA)\s+(.+)$/iu',
            $name,
            $matches
        ) === 1 && preg_match('/administruojami|pvm|gpm|\b1311\b|\b1001\b/iu', $matches[2]) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private static function finalize(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return null;
        }

        $name = self::canonicalName($name) ?? $name;

        // Only rewrite Lithuanian legal-form names to "Name, UAB" (leave foreign / free-text alone).
        if (LithuanianCompanyNameNormalizer::extract($name)['form'] !== null) {
            $normalized = LithuanianCompanyNameNormalizer::normalize($name);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return $name;
    }

    /**
     * Collapse known counterpart spelling variants to one display name.
     */
    private static function canonicalName(string $name): ?string
    {
        $normalized = mb_strtolower(strtr($name, [
            'ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i',
            'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
            '„' => '', '"' => '', '“' => '', '”' => '', "'" => '',
        ]));
        $normalized = trim(preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? '');
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

        $aliases = [
            'uab finansu valdymo sistemos' => 'Finansų valdymo sistemos, UAB',
            'finansu valdymo sistemos uab' => 'Finansų valdymo sistemos, UAB',
            'finansu valdymo sistemos' => 'Finansų valdymo sistemos, UAB',
            'paysera lt uab' => 'Paysera LT, UAB',
            'uab paysera lt' => 'Paysera LT, UAB',
            'paysera lt' => 'Paysera LT, UAB',
            'uab dsv road' => 'DSV Road, UAB',
            'dsv road uab' => 'DSV Road, UAB',
            'dsv road' => 'DSV Road, UAB',
            'valstybine mokesciu inspekcija prie lr finansu' => 'Valstybinė mokesčių inspecija prie LR Finansų',
            'valstybine mokesciu inspekcija prie lr finansy' => 'Valstybinė mokesčių inspecija prie LR Finansų',
            'valstybine mokesciu inspekcija prie lr finans' => 'Valstybinė mokesčių inspecija prie LR Finansų',
            'anthropic' => 'Anthropic, PBC',
            'anthropic claude sub' => 'Anthropic, PBC',
            'anthropic pbc' => 'Anthropic, PBC',
        ];

        return $aliases[$normalized] ?? null;
    }
}
