<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InvoiceAiMapper
{
    /**
     * @param  array<string, mixed>  $hints  Partial fields already extracted by templates/heuristics
     * @return array<string, string|float|null>
     */
    public function map(string $text, array $hints = []): array
    {
        $text = InvoiceOcrService::sanitizeUtf8(trim($text));
        if ($text === '' || ! $this->isEnabled()) {
            return [];
        }

        $own = CompanySetting::instance();

        try {
            $response = Http::timeout(35)
                ->withToken((string) config('services.invoice_ai.key'))
                ->acceptJson()
                ->post($this->endpoint(), [
                    'model' => (string) config('services.invoice_ai.model', 'gpt-4o-mini'),
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->systemPrompt($own),
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->userPayload($text, $hints),
                        ],
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::warning('Invoice AI mapping request failed', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        if (! $response->ok()) {
            Log::warning('Invoice AI mapping HTTP error', [
                'status' => $response->status(),
            ]);

            return [];
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');

        return $this->normalizeMappedFields($this->decodePayload($content), $own);
    }

    public function isEnabled(): bool
    {
        return filled(config('services.invoice_ai.key'));
    }

    private function endpoint(): string
    {
        $base = rtrim((string) config('services.invoice_ai.base_url', 'https://api.openai.com/v1'), '/');

        return $base.'/chat/completions';
    }

    private function systemPrompt(CompanySetting $own): string
    {
        $ownName = trim((string) ($own->company_name ?? ''));
        $ownVat = trim((string) ($own->company_vat ?? ''));
        $ownId = trim((string) ($own->company_id ?? ''));

        return trim(<<<PROMPT
You extract purchase-invoice fields from messy OCR / PDF text (Lithuanian, English, or German).
Return JSON only with keys:
provider_company_name, provider_company_id, provider_company_vat, provider_company_email,
invoice_number, invoice_date, sum_without_vat, vat, sum_inc_vat, currency, confidence.

Rules:
- provider_* is the SELLER / issuer (Pardavėjas / Seller / Lieferant / Rechnungsteller), never the buyer (Pirkėjas / Bill to / GAVĖJAS / Empfänger / Customer).
- Our company is the buyer and must never be the provider: {$ownName} / {$ownId} / {$ownVat}.
- Lithuanian legal forms: prefer "Name, UAB" / "Name, VšĮ" / "Name, MB" / "Name, IĮ" (form at the end). Keep diacritics (Š ų Į ė).
- invoice_date as YYYY-MM-DD (also accept 2026.08.27, 27.08.2026, 27/08/2026).
- Amounts as numbers (use dot decimal). currency is ISO 4217 (EUR, USD, GBP). Default EUR if euro or unmarked.
- If PVM/VAT is 0% / neapmokestinama / reverse charge / reverse-charge, vat is 0.
- invoice_number is the document number (series + number, e.g. NFM-0000253 or SF-2026-10), not an IBAN, order id, or password.
- provider_company_id is the seller company code (usually 7–11 digits in Lithuania), not the buyer.
- provider_company_vat like LT100675917 (no spaces).
- confidence: number from 0 to 1 for overall extraction quality (1 = clear invoice, fields certain).
- Use null for unknown values. Do not invent amounts, dates, or company ids.
- If hints are provided, correct wrong buyer/seller swaps and fill gaps; keep clearly correct hint values.
PROMPT);
    }

    /**
     * @param  array<string, mixed>  $hints
     */
    private function userPayload(string $text, array $hints): string
    {
        $parts = [Str::limit($text, 12000, '')];

        $cleanHints = array_filter(
            $hints,
            static fn ($value, $key): bool => is_string($key)
                && ! str_starts_with($key, '_')
                && $value !== null
                && $value !== '',
            ARRAY_FILTER_USE_BOTH,
        );

        if ($cleanHints !== []) {
            $parts[] = "\n\n---\nPartial extraction hints (may be wrong):\n"
                .json_encode($cleanHints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode('', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $content): array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content));
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, string|float|null>
     */
    private function normalizeMappedFields(array $mapped, CompanySetting $own): array
    {
        $name = $this->nullableString($mapped['provider_company_name'] ?? null);
        if ($name !== null && LithuanianCompanyNameNormalizer::extract($name)['form'] !== null) {
            $normalized = LithuanianCompanyNameNormalizer::normalize($name);
            if ($normalized !== '') {
                $name = $normalized;
            }
        }

        $id = $this->digits($mapped['provider_company_id'] ?? null);
        $vat = $this->nullableString($mapped['provider_company_vat'] ?? null);
        $vat = $vat !== null ? strtoupper(preg_replace('/\s+/', '', $vat) ?? $vat) : null;

        if ($this->isOwnCompany($name, $id, $vat, $own)) {
            $name = null;
            $id = null;
            $vat = null;
        }

        $invoiceNumber = $this->nullableString($mapped['invoice_number'] ?? null);
        if ($invoiceNumber !== null) {
            $invoiceNumber = strtoupper(preg_replace('/\s+/', '', str_replace('=', '-', $invoiceNumber)) ?? $invoiceNumber);
            if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $invoiceNumber) === 1) {
                $invoiceNumber = null;
            }
        }

        $confidence = $this->normalizeConfidence($mapped['confidence'] ?? null);

        $parsed = array_filter([
            'provider_company_name' => $name,
            'provider_company_id' => $id,
            'provider_company_vat' => $vat,
            'provider_company_email' => $this->nullableString($mapped['provider_company_email'] ?? null),
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $this->normalizeDate($mapped['invoice_date'] ?? null),
            'sum_without_vat' => $this->nullableAmount($mapped['sum_without_vat'] ?? null),
            'vat' => $this->nullableAmount($mapped['vat'] ?? null),
            'sum_inc_vat' => $this->nullableAmount($mapped['sum_inc_vat'] ?? null),
            'currency' => $this->normalizeCurrency($mapped['currency'] ?? null),
            'ai_confidence' => $confidence,
        ], static fn ($value): bool => $value !== null && $value !== '');

        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $parsed[$key] = InvoiceOcrService::sanitizeUtf8($value);
            }
        }

        return $parsed;
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $n = (float) $value;
            if ($n > 1 && $n <= 100) {
                $n = $n / 100;
            }

            return round(max(0, min(1, $n)), 2);
        }

        return null;
    }

    private function isOwnCompany(?string $name, ?string $id, ?string $vat, CompanySetting $own): bool
    {
        if ($id !== null && filled($own->company_id) && $id === preg_replace('/\D+/', '', (string) $own->company_id)) {
            return true;
        }

        $ownVat = strtoupper(preg_replace('/\s+/', '', (string) ($own->company_vat ?? '')) ?? '');
        if ($vat !== null && $ownVat !== '' && $vat === $ownVat) {
            return true;
        }

        $ownName = Str::lower(trim((string) ($own->company_name ?? '')));

        return $ownName !== '' && $name !== null && Str::lower($name) === $ownName;
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }

        $code = strtoupper(preg_replace('/[^A-Za-z]/', '', $raw) ?? '');
        if (strlen($code) === 3) {
            return $code;
        }

        return match (true) {
            str_contains($raw, '€') => 'EUR',
            str_contains($raw, '$') => 'USD',
            str_contains($raw, '£') => 'GBP',
            default => null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = InvoiceOcrService::sanitizeUtf8(trim((string) $value));

        return $value !== '' && ! in_array(Str::lower($value), ['null', 'unknown', 'n/a'], true)
            ? Str::limit($value, 255, '')
            : null;
    }

    private function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->nullableString($value)) ?? '';

        return strlen($digits) >= 7 && strlen($digits) <= 11 ? $digits : null;
    }

    private function nullableAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }
        if (! is_string($value)) {
            return null;
        }

        $raw = str_replace([' ', '€'], '', $value);
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = strrpos($raw, ',') > strrpos($raw, '.')
                ? str_replace(['.', ','], ['', '.'], $raw)
                : str_replace(',', '', $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }

        return is_numeric($raw) ? round((float) $raw, 2) : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
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
}
