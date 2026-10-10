<?php

namespace App\Services;

use App\Models\BusinessTripRequest;
use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Reads “other expenses” totals from trip expense-report PDFs (Komandiruotės ataskaita /
 * Business trip expense report), not daily-allowance / grand-total figures.
 */
final class BusinessTripExpensesExtractor
{
    /**
     * Document names that typically contain an other-expenses table.
     */
    private const EXPENSE_NAME_HINTS = [
        'expense',
        'expenses',
        'išlaid',
        'islaidos',
        'ataskait',
        'report',
        'receipt',
        'tickets',
        'hotel',
    ];

    /**
     * Skip pure travel orders (Įsakymas) — they rarely include expense totals.
     */
    private const SKIP_NAME_HINTS = [
        'įsakymas',
        'isakymas',
        'order regarding',
        'order on business',
    ];

    /**
     * Sum expense totals found across trip documents.
     *
     * @return array{amount: ?float, sources: list<string>, details: list<string>}
     */
    public static function extractFromTrip(BusinessTripRequest $trip): array
    {
        $trip->loadMissing('documents');
        $total = 0.0;
        $found = false;
        $sources = [];
        $details = [];

        foreach ($trip->documents as $document) {
            $result = self::extractFromDocument($document);
            if ($result['amount'] === null) {
                if ($result['detail'] !== null) {
                    $details[] = $result['detail'];
                }

                continue;
            }

            $found = true;
            $total += $result['amount'];
            $sources[] = $document->name ?: ('Document #'.$document->getKey());
            if ($result['detail'] !== null) {
                $details[] = $result['detail'];
            }
        }

        return [
            'amount' => $found ? round($total, 2) : null,
            'sources' => $sources,
            'details' => $details,
        ];
    }

    /**
     * Apply extracted expenses onto the trip when empty (or when $overwrite is true).
     *
     * @return array{updated: bool, amount: ?float, sources: list<string>, message: string}
     */
    public static function applyToTrip(BusinessTripRequest $trip, bool $overwrite = false): array
    {
        $current = $trip->expensesAmount();
        if (! $overwrite && $current > 0.009) {
            return [
                'updated' => false,
                'amount' => $current,
                'sources' => [],
                'message' => 'Expenses already set ('.\App\Support\Money::format($current).').',
            ];
        }

        $extracted = self::extractFromTrip($trip);
        if ($extracted['amount'] === null) {
            return [
                'updated' => false,
                'amount' => null,
                'sources' => [],
                'message' => 'No other-expenses total found in attached documents.',
            ];
        }

        $trip->forceFill([
            'expenses_amount' => $extracted['amount'],
        ])->save();

        $trip->syncPaymentStatusFromBankLinks();

        return [
            'updated' => true,
            'amount' => $extracted['amount'],
            'sources' => $extracted['sources'],
            'message' => 'Expenses set to '.\App\Support\Money::format($extracted['amount'])
                .' from: '.implode(', ', $extracted['sources']),
        ];
    }

    /**
     * @return array{amount: ?float, detail: ?string}
     */
    public static function extractFromDocument(Document $document): array
    {
        $name = mb_strtolower(trim((string) ($document->name ?? '')));
        $fileName = mb_strtolower(DocumentBinaryStore::downloadFileName($document));
        $haystack = $name.' '.$fileName;

        foreach (self::SKIP_NAME_HINTS as $skip) {
            if (str_contains($haystack, $skip) && ! self::nameLooksLikeExpenseReport($haystack)) {
                return ['amount' => null, 'detail' => null];
            }
        }

        try {
            if (! DocumentBinaryStore::hasFile($document)) {
                return ['amount' => null, 'detail' => 'No file for document #'.$document->getKey()];
            }

            $binary = DocumentBinaryStore::getBinary($document);
        } catch (Throwable $exception) {
            Log::warning('Business trip expense document download failed', [
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);

            return ['amount' => null, 'detail' => $exception->getMessage()];
        }

        $ext = strtolower((string) pathinfo(DocumentBinaryStore::downloadFileName($document), PATHINFO_EXTENSION));
        if (! in_array($ext, ['pdf', ''], true) && ! str_starts_with($binary, '%PDF')) {
            return ['amount' => null, 'detail' => null];
        }

        $tmp = storage_path('app/tmp-trip-expense-'.Str::uuid().'.pdf');
        try {
            file_put_contents($tmp, $binary);
            $text = self::pdfText($tmp);
            if (trim($text) === '') {
                // Scanned PDF fallback via invoice OCR field map.
                $ocr = app(InvoiceOcrService::class)->extract($tmp);
                $amount = self::amountFromOcrFallback($ocr, $haystack);

                return [
                    'amount' => $amount,
                    'detail' => $amount !== null
                        ? 'OCR fallback on '.$document->name
                        : null,
                ];
            }

            $amount = self::parseOtherExpensesTotal($text);
            if ($amount === null && self::nameLooksLikeExpenseReport($haystack)) {
                $amount = self::sumExpenseLineItems($text);
            }

            return [
                'amount' => $amount,
                'detail' => $amount !== null
                    ? 'Parsed '.$document->name.': '.\App\Support\Money::format($amount)
                    : null,
            ];
        } catch (Throwable $exception) {
            Log::warning('Business trip expense parse failed', [
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);

            return ['amount' => null, 'detail' => $exception->getMessage()];
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private static function nameLooksLikeExpenseReport(string $haystack): bool
    {
        foreach (self::EXPENSE_NAME_HINTS as $hint) {
            if (str_contains($haystack, $hint)) {
                return true;
            }
        }

        return false;
    }

    private static function pdfText(string $absolutePath): string
    {
        try {
            $text = (new PdfParser)->parseFile($absolutePath)->getText();
        } catch (Throwable) {
            return '';
        }

        return is_string($text) ? $text : '';
    }

    public static function parseOtherExpensesTotal(string $text): ?float
    {
        $normalized = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        // Normalize common Lithuanian š/Š to ASCII for reliable matching regardless of source encoding.
        $ascii = strtr($normalized, [
            'š' => 's', 'Š' => 'S',
            'ą' => 'a', 'Ą' => 'A',
            'č' => 'c', 'Č' => 'C',
            'ę' => 'e', 'Ę' => 'E',
            'ė' => 'e', 'Ė' => 'E',
            'į' => 'i', 'Į' => 'I',
            'ų' => 'u', 'Ų' => 'U',
            'ū' => 'u', 'Ū' => 'U',
            'ž' => 'z', 'Ž' => 'Z',
        ]);

        $patterns = [
            // “Kitos išlaidos iš viso / Other expenses total: 279.80”
            // also “Kitos išlaidos iš viso: / Other expenses total: 494,49”
            '/kitos\s+islaidos\s+is\s+viso\s*[:\-]?\s*(?:\/\s*)?(?:other\s+expenses\s+total\s*)?[:\-]?\s*([\d\s.,]+)/i',
            '/other\s+expenses\s+total\s*[:\-]?\s*([\d\s.,]+)/i',
            '/islaidu\s+suma\s*[:\-]?\s*([\d\s.,]+)/i',
            '/suma\s+islaidu\s*[:\-]?\s*([\d\s.,]+)/i',
            '/expenses?\s+total\s*[:\-]?\s*([\d\s.,]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $ascii, $m)) {
                $amount = self::parseMoney($m[1]);
                if ($amount !== null && $amount > 0) {
                    return $amount;
                }
            }
        }

        return null;
    }

    /**
     * Sum amounts in the OTHER EXPENSES section when no explicit total line exists.
     */
    public static function sumExpenseLineItems(string $text): ?float
    {
        $normalized = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $ascii = strtr($normalized, [
            'š' => 's', 'Š' => 'S',
            'ą' => 'a', 'Ą' => 'A',
            'č' => 'c', 'Č' => 'C',
            'ę' => 'e', 'Ę' => 'E',
            'ė' => 'e', 'Ė' => 'E',
            'į' => 'i', 'Į' => 'I',
            'ų' => 'u', 'Ų' => 'U',
            'ū' => 'u', 'Ū' => 'U',
            'ž' => 'z', 'Ž' => 'Z',
        ]);

        if (! preg_match('/kitos\s+islaidos|other\s+expenses/i', $ascii)) {
            return null;
        }

        // Take text between OTHER EXPENSES heading and “Other expenses total” / GRAND TOTAL / signature.
        if (! preg_match(
            '/(?:kitos\s+islaidos\s*\/\s*other\s+expenses|other\s+expenses)(.*?)(?:kitos\s+islaidos\s+is\s+viso|other\s+expenses\s+total|bendra\s+suma|grand\s+total|parasas|signature)/is',
            $ascii,
            $section,
        )) {
            return null;
        }

        $chunk = $section[1];
        if (! preg_match_all('/(\d{1,3}(?:[.,]\d{3})*[.,]\d{2})\s*(?:€|eur)?/i', $chunk, $matches)) {
            return null;
        }

        $sum = 0.0;
        $count = 0;
        foreach ($matches[1] as $raw) {
            $amount = self::parseMoney($raw);
            // Ignore tiny/date-like noise; expense lines are typically >= 1 EUR.
            if ($amount === null || $amount < 1) {
                continue;
            }
            // Skip year-looking numbers.
            if ($amount >= 2000 && $amount <= 2100 && fmod($amount, 1.0) < 0.001) {
                continue;
            }
            $sum += $amount;
            $count++;
        }

        return $count > 0 ? round($sum, 2) : null;
    }

    /**
     * @param  array<string, mixed>  $ocr
     */
    private static function amountFromOcrFallback(array $ocr, string $haystack): ?float
    {
        if (! self::nameLooksLikeExpenseReport($haystack)) {
            return null;
        }

        // OCR often returns allowance/grand total as sum_inc_vat — too unsafe without
        // an other-expenses label in the raw name. Prefer null over wrong fill.
        return null;
    }

    private static function parseMoney(string $raw): ?float
    {
        $raw = trim(str_replace(["\xc2\xa0", ' '], '', $raw));
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $raw)) {
            // 1.234,56
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $raw)) {
            // 1,234.56
            $raw = str_replace(',', '', $raw);
        } elseif (substr_count($raw, ',') === 1 && substr_count($raw, '.') === 0) {
            $raw = str_replace(',', '.', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return round((float) $raw, 2);
    }
}
