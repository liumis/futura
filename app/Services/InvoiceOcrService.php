<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class InvoiceOcrService
{
    /**
     * Provider-specific extraction rules for recurring invoice layouts.
     *
     * @var array<string, array<string, string>>
     */
    private array $providerTemplates = [
        'telia' => [
            'provider_name' => '/\b(Telia(?:\s+[A-Za-z]+){0,3})\b/i',
            'invoice_date' => '/\b(?:invoice\s*date|date)\b[:\s\-]*([0-3]?\d[\/\.-][01]?\d[\/\.-](?:20)?\d{2}|\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/i',
            'sum_without_vat' => '/\b(?:subtotal|net\s*amount|sum\s*without\s*vat)\b[^\d]{0,20}([\d\.,]+)/i',
            'vat' => '/\b(?:vat|pvm)\b[^\d]{0,20}([\d\.,]+)/i',
            'sum_inc_vat' => '/\b(?:total|amount\s*due|payable)\b[^\d]{0,20}([\d\.,]+)/i',
            'company_vat' => '/\b(?:VAT|PVM)\b[:\s#\-]*([A-Z0-9\-]+)/i',
            'company_email' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        ],
        'tele2' => [
            'provider_name' => '/\b(Tele2(?:\s+[A-Za-z]+){0,3})\b/i',
            'invoice_date' => '/\b(?:invoice\s*date|date)\b[:\s\-]*([0-3]?\d[\/\.-][01]?\d[\/\.-](?:20)?\d{2}|\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/i',
            'sum_without_vat' => '/\b(?:subtotal|net\s*total)\b[^\d]{0,20}([\d\.,]+)/i',
            'vat' => '/\b(?:vat|pvm)\b[^\d]{0,20}([\d\.,]+)/i',
            'sum_inc_vat' => '/\b(?:total|amount\s*due)\b[^\d]{0,20}([\d\.,]+)/i',
            'company_vat' => '/\b(?:VAT|PVM)\b[:\s#\-]*([A-Z0-9\-]+)/i',
            'company_email' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        ],
        'bite' => [
            'provider_name' => '/\b(Bite(?:\s+[A-Za-z]+){0,3})\b/i',
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
     * @return array<string, string|float|null>
     */
    public function extract(string $absoluteFilePath): array
    {
        $apiKey = (string) env('OCR_SPACE_API_KEY', '');
        if ($apiKey === '' || ! is_file($absoluteFilePath)) {
            return [];
        }

        $response = Http::timeout(45)
            ->asMultipart()
            ->post('https://api.ocr.space/parse/image', [
                [
                    'name' => 'apikey',
                    'contents' => $apiKey,
                ],
                [
                    'name' => 'language',
                    'contents' => 'eng',
                ],
                [
                    'name' => 'isOverlayRequired',
                    'contents' => 'false',
                ],
                [
                    'name' => 'file',
                    'contents' => fopen($absoluteFilePath, 'r'),
                    'filename' => basename($absoluteFilePath),
                ],
            ]);

        if (! $response->ok()) {
            return [];
        }

        $payload = $response->json();
        $text = (string) data_get($payload, 'ParsedResults.0.ParsedText', '');
        if ($text === '') {
            return [];
        }

        return $this->parseRawText($text);
    }

    /**
     * @return array<string, string|float|null>
     */
    private function parseRawText(string $text): array
    {
        $templateResult = $this->extractWithTemplate($text);

        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/', $text) ?: [],
        )));

        $provider = $templateResult['provider_company_name'] ?? $this->guessProviderName($lines);

        return array_filter([
            'provider_company_name' => $provider,
            'provider_company_email' => $templateResult['provider_company_email'] ?? $this->extractByRegex($text, '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i'),
            'provider_company_vat' => $templateResult['provider_company_vat'] ?? $this->extractByRegex($text, '/\b(VAT|PVM)\b[:\s#\-]*([A-Z0-9\-]+)/i', 2),
            'invoice_date' => $templateResult['invoice_date'] ?? $this->normalizeDate($this->extractByRegex(
                $text,
                '/\b(?:invoice\s*date|date|sąskaitos\s+data)\b[:\s\-]*([0-3]?\d[\/\.-][01]?\d[\/\.-](?:20)?\d{2}|\d{4}[\/\.-][01]?\d[\/\.-][0-3]?\d)/iu',
                1
            )),
            'sum_without_vat' => $templateResult['sum_without_vat'] ?? $this->extractAmount($text, '/\b(?:subtotal|net\s*total|sum\s*without\s*vat|suma\s+be\s+pvm)\b[^\d]{0,15}([\d\s\.,]+)/iu'),
            'vat' => $templateResult['vat'] ?? $this->extractAmount($text, '/\b(?:vat|pvm(?:\s*suma)?)\b[^\d]{0,15}([\d\s\.,]+)/iu'),
            'sum_inc_vat' => $templateResult['sum_inc_vat'] ?? $this->extractAmount($text, '/\b(?:total|amount\s*due|grand\s*total|sum\s*inc\.?\s*vat|bendra\s+suma|iš\s+viso)\b[^\d]{0,15}([\d\s\.,]+)/iu'),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, string|float|null>
     */
    private function extractWithTemplate(string $text): array
    {
        $providerKey = $this->detectProviderKey($text);
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

    private function detectProviderKey(string $text): ?string
    {
        $normalized = Str::lower($text);

        return match (true) {
            Str::contains($normalized, 'telia') => 'telia',
            Str::contains($normalized, 'tele2') => 'tele2',
            Str::contains($normalized, 'bite') => 'bite',
            Str::contains($normalized, 'woocommerce ireland') => 'woocommerce',
            Str::contains($normalized, 'spiritus group') => 'spiritus',
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

        foreach ($lines as $line) {
            $candidate = trim($line, " \t\n\r\0\x0B:.-");
            if (mb_strlen($candidate) < 3) {
                continue;
            }
            if (Str::contains(Str::lower($candidate), ['invoice', 'bill to', 'date', 'vat', '@'])) {
                continue;
            }

            return Str::limit($candidate, 255, '');
        }

        return null;
    }

    private function extractByRegex(string $text, string $pattern, int $group = 0): ?string
    {
        if (! preg_match($pattern, $text, $matches)) {
            return null;
        }

        $value = trim((string) ($matches[$group] ?? ''));

        return $value !== '' ? Str::limit($value, 255, '') : null;
    }

    private function normalizeDate(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
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
        if (! preg_match($pattern, $text, $matches)) {
            return null;
        }

        $raw = str_replace(' ', '', (string) ($matches[1] ?? ''));
        $raw = preg_replace('/[^\d,.\-]/', '', $raw) ?? $raw;
        $raw = str_replace(',', '.', $raw);
        if (! is_numeric($raw)) {
            return null;
        }

        return round((float) $raw, 2);
    }
}
