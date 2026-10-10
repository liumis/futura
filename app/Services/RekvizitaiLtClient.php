<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fetches public company details from rekvizitai.vz.lt company pages.
 */
class RekvizitaiLtClient
{
    private const BASE = 'https://rekvizitai.vz.lt/en/company';

    /**
     * Known slug overrides when auto-slug fails (old brand / renamed companies).
     *
     * @var array<string, string>
     */
    private const SLUG_OVERRIDES = [
        'dsv road uab' => 'schenker',
        'dsv road' => 'schenker',
        'felit uab - ikea lithuania' => 'uab_felit',
        'felit uab' => 'uab_felit',
        'uab "finansų valdymo sistemos"' => 'finansu_valdymo_sistemos',
        'finansų valdymo sistemos, uab' => 'finansu_valdymo_sistemos',
        'finansu valdymo sistemos, uab' => 'finansu_valdymo_sistemos',
        'uab “realm juridical“' => 'realm_juridical',
        'uab "realm juridical"' => 'realm_juridical',
        'všį šiaulių regiono atliekų tvarkymo centras' => 'siauliu_regiono_atlieku_tvarkymo_centras',
        'textelita mb' => 'textelita_mb',
        'uab futura textiles europe' => 'futura_textiles_europe',
        'futura textiles europe, uab' => 'futura_textiles_europe',
        'vinilas, uab' => 'vinilas',
        'uab "vinilas"' => 'vinilas',
        'uab vinilas' => 'vinilas',
        'siauliu regiono atlieku tvarkymo centras, vsi' => 'siauliu_regiono_atlieku_tvarkymo_centras',
        'siauliu regiono atlieku tvarkymo centras, vsį' => 'siauliu_regiono_atlieku_tvarkymo_centras',
    ];

    /**
     * @return array{
     *     name: ?string,
     *     company_id: ?string,
     *     company_vat: ?string,
     *     company_address: ?string,
     *     company_phone: ?string,
     *     company_email: ?string,
     *     company_country: string,
     *     url: string,
     *     slug: string
     * }|null
     */
    public function lookupByName(string $companyName, ?string $expectedCompanyId = null): ?array
    {
        foreach ($this->slugCandidates($companyName) as $slug) {
            $data = $this->fetchSlug($slug);
            if ($data === null) {
                continue;
            }

            if ($this->isAcceptableMatch($companyName, $expectedCompanyId, $data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function slugCandidates(string $companyName): array
    {
        $normalized = $this->normalizeName($companyName);
        $slugs = [];

        foreach (self::SLUG_OVERRIDES as $key => $slug) {
            if ($this->normalizeName($key) === $normalized) {
                $slugs[] = $slug;
                break;
            }
        }

        $core = $this->slugify($companyName);
        if ($core !== '') {
            $slugs[] = $core;
            $slugs[] = 'uab_'.$core;
            $slugs[] = $core.'_uab';
            $slugs[] = 'mb_'.$core;
            $slugs[] = $core.'_mb';
        }

        // Drop legal-form tokens then slug again.
        $withoutForm = trim(preg_replace(
            '/\b(uab|mb|ab|všį|vsi|idv|įdv)\b/iu',
            ' ',
            $companyName,
        ) ?? $companyName);
        $withoutForm = trim(str_replace(['"', "'", '„', '“', '”', '«', '»'], '', $withoutForm));
        $core2 = $this->slugify($withoutForm);
        if ($core2 !== '' && $core2 !== $core) {
            $slugs[] = $core2;
            $slugs[] = 'uab_'.$core2;
            $slugs[] = $core2.'_mb';
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    /**
     * @return array{
     *     name: ?string,
     *     company_id: ?string,
     *     company_vat: ?string,
     *     company_address: ?string,
     *     company_phone: ?string,
     *     company_email: ?string,
     *     company_country: string,
     *     url: string,
     *     slug: string
     * }|null
     */
    public function fetchSlug(string $slug): ?array
    {
        $url = self::BASE.'/'.rawurlencode($slug).'/';

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; FuturaCRM/1.0; +https://futuratextiles.eu)',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();
        if (stripos($html, 'Error 404') !== false || stripos($html, 'Page not found') !== false) {
            return null;
        }

        return $this->parseCompanyHtml($html, $slug, $url);
    }

    /**
     * @param  array{
     *     name: ?string,
     *     company_id: ?string,
     *     company_vat: ?string,
     *     company_address: ?string,
     *     company_phone: ?string,
     *     company_email: ?string,
     *     company_country: string,
     *     url: string,
     *     slug: string
     * }  $data
     */
    private function isAcceptableMatch(string $companyName, ?string $expectedCompanyId, array $data): bool
    {
        $expectedId = preg_replace('/\D+/', '', (string) $expectedCompanyId) ?? '';
        $foundId = preg_replace('/\D+/', '', (string) ($data['company_id'] ?? '')) ?? '';

        if (strlen($expectedId) === 9) {
            if ($foundId !== '' && $expectedId === $foundId) {
                return true;
            }

            // Exact name match can correct a wrong/OCR company code.
            $left = $this->normalizeName($companyName);
            $right = $this->normalizeName((string) ($data['name'] ?? ''));

            return $left !== '' && $left === $right;
        }

        // Reject AUTO- / OCR-copied wrong codes when name clearly mismatches.
        $left = $this->normalizeName($companyName);
        $right = $this->normalizeName((string) ($data['name'] ?? ''));
        if ($left === '' || $right === '') {
            return false;
        }

        if ($left === $right || str_contains($right, $left) || str_contains($left, $right)) {
            return true;
        }

        $leftTokens = $this->nameTokens($left);
        $rightTokens = $this->nameTokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $overlap = count(array_intersect($leftTokens, $rightTokens));

        return $overlap >= min(2, count($leftTokens));
    }

    /**
     * @return array{
     *     name: ?string,
     *     company_id: ?string,
     *     company_vat: ?string,
     *     company_address: ?string,
     *     company_phone: ?string,
     *     company_email: ?string,
     *     company_country: string,
     *     url: string,
     *     slug: string
     * }|null
     */
    private function parseCompanyHtml(string $html, string $slug, string $url): ?array
    {
        $ld = $this->parseJsonLd($html);

        $name = null;
        if (preg_match('/<h1[^>]*>\s*(.*?)\s*<\/h1>/isu', $html, $m) === 1) {
            $name = $this->plain($m[1]);
        }
        $name = $name ?: ($ld['name'] ?? null);

        $companyId = $this->elementById($html, 'ccode')
            ?? $this->tableValue($html, 'Registration code')
            ?? $this->tableValue($html, 'Įmonės kodas');
        $vat = $this->elementById($html, 'pcode')
            ?? $this->tableValue($html, 'VAT')
            ?? $this->tableValue($html, 'PVM mokėtojo kodas')
            ?? ($ld['vatID'] ?? null);
        $address = $this->elementById($html, 'copy_address')
            ?? $this->tableValue($html, 'Address')
            ?? $this->tableValue($html, 'Adresas')
            ?? ($ld['address'] ?? null);
        $phone = $this->firstTelHref($html)
            ?? $this->tableValue($html, 'Mobile phone')
            ?? $this->tableValue($html, 'Phone')
            ?? $this->tableValue($html, 'Telefonas');
        $email = $this->extractEmail($html);

        if ($companyId === null && $name === null) {
            return null;
        }

        if ($phone !== null) {
            $phone = preg_replace('/\s*\(\+?\d+\)\s*/', '', $phone) ?? $phone;
            $phone = trim(preg_replace('/\s+/', ' ', $phone) ?? $phone);
        }

        if ($address !== null && (str_contains($address, 'urlserver') || str_contains($address, 'APIHost'))) {
            $address = $ld['address'] ?? null;
        }

        return [
            'name' => $name,
            'company_id' => $companyId !== null ? preg_replace('/\D+/', '', $companyId) : null,
            'company_vat' => $vat !== null ? strtoupper(preg_replace('/\s+/', '', $vat) ?? $vat) : null,
            'company_address' => $address,
            'company_phone' => $phone,
            'company_email' => $email,
            'company_country' => 'Lithuania',
            'url' => $url,
            'slug' => $slug,
        ];
    }

    /**
     * @return array{name?: string, vatID?: string, address?: string}
     */
    private function parseJsonLd(string $html): array
    {
        if (preg_match('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/isu', $html, $m) !== 1) {
            return [];
        }

        $json = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (! is_array($json)) {
            return [];
        }

        $out = [];
        if (! empty($json['name']) && is_string($json['name'])) {
            $out['name'] = trim($json['name']);
        }
        if (! empty($json['vatID']) && is_string($json['vatID'])) {
            $out['vatID'] = trim($json['vatID']);
        }
        if (isset($json['address']) && is_array($json['address'])) {
            $parts = array_filter([
                $json['address']['streetAddress'] ?? null,
                trim(($json['address']['postalCode'] ?? '').' '.($json['address']['addressLocality'] ?? '')),
            ], static fn ($p): bool => filled($p));
            if ($parts !== []) {
                $out['address'] = implode(', ', $parts);
            }
        }

        return $out;
    }

    private function elementById(string $html, string $id): ?string
    {
        $id = preg_quote($id, '/');
        if (preg_match('/<[^>]+id=["\']'.$id.'["\'][^>]*>(.*?)<\/[^>]+>/isu', $html, $m) === 1) {
            $value = $this->plain($m[1]);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function firstTelHref(string $html): ?string
    {
        if (preg_match('/href=["\']tel:([^"\']+)["\']/iu', $html, $m) === 1) {
            return trim(rawurldecode($m[1]));
        }

        return null;
    }

    private function tableValue(string $html, string $label): ?string
    {
        $label = preg_quote($label, '/');
        if (preg_match(
            '/<t[dh][^>]*>\s*'.$label.'\s*<\/t[dh]>\s*<t[dh][^>]*>\s*(.*?)\s*<\/t[dh]>/isu',
            $html,
            $m,
        ) === 1) {
            return $this->plain($m[1]);
        }

        return null;
    }

    private function labeledBlock(string $html, string $label): ?string
    {
        $label = preg_quote($label, '/');
        if (preg_match(
            '/'.$label.'\s*<\/[^>]+>\s*<[^>]+>\s*(.*?)\s*<\/[^>]+>/isu',
            $html,
            $m,
        ) === 1) {
            $value = $this->plain($m[1]);

            return $value !== '' ? $value : null;
        }

        // Markdown-converted pages sometimes use plain label then value nearby.
        if (preg_match('/'.$label.'\s*\n+([^\n<]+)/iu', $html, $m) === 1) {
            $value = $this->plain($m[1]);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function extractEmail(string $html): ?string
    {
        if (preg_match('/mailto:([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/iu', $html, $m) === 1) {
            return strtolower($m[1]);
        }

        if (preg_match('/\b([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})\b/iu', $html, $m) === 1) {
            $email = strtolower($m[1]);
            if (! str_contains($email, 'rekvizitai') && ! str_contains($email, 'creditinfo')) {
                return $email;
            }
        }

        return null;
    }

    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function slugify(string $name): string
    {
        $name = $this->transliterate($name);
        $name = Str::lower($name);
        $name = preg_replace('/\b(uab|mb|ab|vsi|idv)\b/u', ' ', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/u', '_', $name) ?? $name;
        $name = trim($name, '_');

        return $name;
    }

    private function transliterate(string $value): string
    {
        $map = [
            'ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i',
            'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
            'Ą' => 'A', 'Č' => 'C', 'Ę' => 'E', 'Ė' => 'E', 'Į' => 'I',
            'Š' => 'S', 'Ų' => 'U', 'Ū' => 'U', 'Ž' => 'Z',
        ];

        return strtr($value, $map);
    }

    private function normalizeName(string $name): string
    {
        $name = $this->transliterate($name);
        $name = Str::lower($name);
        $name = str_replace(['"', "'", '„', '“', '”', '«', '»', '`'], '', $name);
        $name = preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /**
     * @return list<string>
     */
    private function nameTokens(string $normalized): array
    {
        $stop = ['uab', 'mb', 'ab', 'vsi', 'idv', 'and', 'the', 'ltd', 'limited'];

        return array_values(array_filter(
            explode(' ', $normalized),
            static fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, $stop, true),
        ));
    }
}
