<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\OriginCountry;
use Illuminate\Support\Str;

class ContactCountryResolver
{
    /**
     * ISO-2 / VAT prefix → country name (must exist in origin_countries).
     *
     * @var array<string, string>
     */
    private const VAT_PREFIX_COUNTRIES = [
        'AT' => 'Austria',
        'BE' => 'Belgium',
        'BG' => 'Bulgaria',
        'CY' => 'Cyprus',
        'CZ' => 'Czech Republic',
        'DE' => 'Germany',
        'DK' => 'Denmark',
        'EE' => 'Estonia',
        'EL' => 'Greece',
        'ES' => 'Spain',
        'FI' => 'Finland',
        'FR' => 'France',
        'GR' => 'Greece',
        'HR' => 'Croatia',
        'HU' => 'Hungary',
        'IE' => 'Ireland',
        'IT' => 'Italy',
        'LT' => 'Lithuania',
        'LU' => 'Luxembourg',
        'LV' => 'Latvia',
        'MT' => 'Malta',
        'NL' => 'Netherlands',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'RO' => 'Romania',
        'SE' => 'Sweden',
        'SI' => 'Slovenia',
        'SK' => 'Slovakia',
        'GB' => 'United Kingdom',
        'UK' => 'United Kingdom',
    ];

    /**
     * Email TLD → country.
     *
     * @var array<string, string>
     */
    private const EMAIL_TLD_COUNTRIES = [
        'lt' => 'Lithuania',
        'de' => 'Germany',
        'es' => 'Spain',
        'pl' => 'Poland',
        'ee' => 'Estonia',
        'lv' => 'Latvia',
        'fi' => 'Finland',
        'se' => 'Sweden',
        'dk' => 'Denmark',
        'cz' => 'Czech Republic',
        'sk' => 'Slovakia',
        'hu' => 'Hungary',
        'ro' => 'Romania',
        'it' => 'Italy',
        'fr' => 'France',
        'pt' => 'Portugal',
        'nl' => 'Netherlands',
        'be' => 'Belgium',
        'at' => 'Austria',
        'ie' => 'Ireland',
        'uk' => 'United Kingdom',
        'co.uk' => 'United Kingdom',
    ];

    /**
     * Infer a country name from contact fields, or null when unknown.
     */
    public static function infer(?Contact $contact = null, ?string $name = null, ?string $companyId = null, ?string $vat = null, ?string $email = null, ?string $address = null): ?string
    {
        $name = trim((string) ($name ?? $contact?->company_name ?? ''));
        $companyId = trim((string) ($companyId ?? $contact?->company_id ?? ''));
        $vat = trim((string) ($vat ?? $contact?->company_vat ?? ''));
        $email = trim((string) ($email ?? $contact?->company_email ?? $contact?->contact_email ?? ''));
        $address = trim((string) ($address ?? $contact?->company_address ?? ''));

        foreach ([$vat, $companyId] as $code) {
            $fromVat = self::fromVatOrCompanyCode($code);
            if ($fromVat !== null) {
                return $fromVat;
            }
        }

        $fromEmail = self::fromEmail($email);
        if ($fromEmail !== null) {
            return $fromEmail;
        }

        $fromName = self::fromCompanyName($name);
        if ($fromName !== null) {
            return $fromName;
        }

        $fromAddress = self::fromAddress($address);
        if ($fromAddress !== null) {
            return $fromAddress;
        }

        return null;
    }

    /**
     * Resolve country for create/update: keep a real existing value unless it is a known false default (Albania with no evidence).
     */
    public static function resolveForContact(Contact $contact, bool $fixFalseAlbania = true): ?string
    {
        $current = trim((string) ($contact->company_country ?? ''));
        $inferred = self::infer($contact);

        if ($current !== '' && ! ($fixFalseAlbania && self::isSuspiciousAlbania($current, $inferred, $contact))) {
            return self::normalize($current) ?? $current;
        }

        return $inferred;
    }

    public static function isSuspiciousAlbania(string $current, ?string $inferred, Contact $contact): bool
    {
        if (! self::isAlbania($current)) {
            return false;
        }

        // Albania is only trusted when something actually points to Albania.
        if ($inferred !== null && ! self::isAlbania($inferred)) {
            return true;
        }

        $hay = Str::upper(implode(' ', array_filter([
            $contact->company_name,
            $contact->company_id,
            $contact->company_vat,
            $contact->company_email,
            $contact->company_address,
        ])));

        return ! str_contains($hay, 'ALBANIA')
            && ! preg_match('/\bAL[- ]?\d/u', $hay)
            && ! str_ends_with(Str::lower((string) $contact->company_email), '.al');
    }

    public static function isAlbania(?string $country): bool
    {
        $normalized = Str::lower(trim((string) $country));

        return in_array($normalized, ['albania', 'al'], true);
    }

    public static function normalize(?string $country): ?string
    {
        $trimmed = trim((string) $country);
        if ($trimmed === '') {
            return null;
        }

        $aliases = [
            'LT' => 'Lithuania',
            'Lietuva' => 'Lithuania',
            'LIETUVA' => 'Lithuania',
            'AL' => 'Albania',
            'DE' => 'Germany',
            'ES' => 'Spain',
            'EE' => 'Estonia',
            'PL' => 'Poland',
            'UK' => 'United Kingdom',
            'GB' => 'United Kingdom',
            'Czechia' => 'Czech Republic',
        ];

        $name = $aliases[$trimmed] ?? $trimmed;

        return OriginCountry::query()->where('name', $name)->exists() ? $name : $name;
    }

    public static function ensureOriginCountry(string $name): void
    {
        OriginCountry::query()->firstOrCreate(['name' => $name]);
    }

    private static function fromVatOrCompanyCode(string $code): ?string
    {
        $clean = Str::upper(preg_replace('/[\s.\-]/u', '', $code) ?? '');
        if ($clean === '' || str_starts_with($clean, 'AUTO')) {
            return null;
        }

        // Spanish CIF letters (A/B/C/D/E/F/G/H/J/N/P/Q/R/S/U/V/W) + 7–8 digits
        if (preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7,8}[A-Z0-9]?$/u', $clean)) {
            return 'Spain';
        }

        // Lithuanian individual / personal codes often stored as "I.V nr. …"
        if (preg_match('/^I\.?V\.?\s*N?R\.?\s*\d+/iu', $code) || preg_match('/^IVNR\d+/u', $clean)) {
            return 'Lithuania';
        }

        // Plain LT company code (9 digits) without prefix
        if (preg_match('/^\d{9}$/u', $clean)) {
            return 'Lithuania';
        }

        // Polish NIP (10 digits)
        if (preg_match('/^\d{10}$/u', $clean)) {
            return 'Poland';
        }

        // Estonian registry code (8 digits) — weak alone; prefer with OÜ in name
        if (preg_match('/^(LT|DE|ES|EE|PL|LV|FI|SE|DK|CZ|SK|HU|RO|IT|FR|PT|NL|BE|AT|IE|GB|UK|EL|GR|HR|BG|CY|MT|LU|SI)([A-Z0-9]+)$/u', $clean, $m)) {
            return self::VAT_PREFIX_COUNTRIES[$m[1]] ?? null;
        }

        return null;
    }

    private static function fromEmail(string $email): ?string
    {
        $email = Str::lower(trim($email));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $host = Str::after($email, '@');
        foreach (self::EMAIL_TLD_COUNTRIES as $tld => $country) {
            if (str_ends_with($host, '.'.$tld) || $host === $tld) {
                return $country;
            }
        }

        return null;
    }

    private static function fromCompanyName(string $name): ?string
    {
        $upper = ' '.Str::upper(trim($name)).' ';
        if (trim($upper) === '') {
            return null;
        }

        $patterns = [
            '/\bUAB\b/u' => 'Lithuania',
            '/\bMB\b/u' => 'Lithuania',
            '/\bVŠĮ\b/u' => 'Lithuania',
            '/\bVSI\b/u' => 'Lithuania',
            '/INDIVIDU/u' => 'Lithuania',
            '/\bOÜ\b/u' => 'Estonia',
            '/\bOU\b/u' => 'Estonia',
            '/\bAS\b/u' => 'Estonia',
            '/\bOY\b/u' => 'Finland',
            '/\bGMBH\b/u' => 'Germany',
            '/\bAG\b/u' => 'Germany',
            '/\bS\.?L\.?\b/u' => 'Spain',
            '/ASOCIACI/u' => 'Spain',
            '/\bSP\.?\s*Z\b/u' => 'Poland',
            '/\bS\.?R\.?O\.?\b/u' => 'Czech Republic',
            '/\bKFT\b/u' => 'Hungary',
            '/\bLTD\b/u' => 'United Kingdom',
            '/\bLIMITED\b/u' => 'United Kingdom',
        ];

        foreach ($patterns as $pattern => $country) {
            if (preg_match($pattern, $upper)) {
                // "ASSOCIATION" must not match Estonian "AS"
                if ($country === 'Estonia' && $pattern === '/\bAS\b/u' && str_contains($upper, 'ASSOCIATION')) {
                    continue;
                }

                return $country;
            }
        }

        if (preg_match('/[ėūįšžčąę]/iu', $name) && preg_match('/individu/iu', $name)) {
            return 'Lithuania';
        }

        return null;
    }

    private static function fromAddress(string $address): ?string
    {
        $upper = Str::upper($address);
        if ($upper === '' || str_contains($upper, 'AUTO-CREATED')) {
            return null;
        }

        $map = [
            'LITHUANIA' => 'Lithuania',
            'LIETUVA' => 'Lithuania',
            'GERMANY' => 'Germany',
            'DEUTSCHLAND' => 'Germany',
            'SPAIN' => 'Spain',
            'ESPAÑA' => 'Spain',
            'ESPANA' => 'Spain',
            'ESTONIA' => 'Estonia',
            'POLAND' => 'Poland',
            'POLSKA' => 'Poland',
            'UNITED KINGDOM' => 'United Kingdom',
            'LATVIA' => 'Latvia',
            'LATVIJA' => 'Latvia',
        ];

        foreach ($map as $needle => $country) {
            if (str_contains($upper, $needle)) {
                return $country;
            }
        }

        return null;
    }
}
