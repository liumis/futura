<?php

namespace App\Services\BankStatements;

use App\Models\BankStatementTransaction;
use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Recovers a usable counterpart/company name when Excel/PDF only stored
 * remittance text in the counterpart column (e.g. "REFUND 2622153237 8MXWT4").
 */
class BankStatementCounterpartResolver
{
    public static function isRemittanceOnly(?string $name): bool
    {
        if ($name === null) {
            return true;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return true;
        }

        // Strip leading doc tokens: "FT… · REFUND …" / "52 · pav (refund)"
        $name = preg_replace('/^\s*[^\s·•]+\s*[·•]\s*/u', '', $name) ?? $name;
        $name = trim($name);

        $normalized = mb_strtolower($name);
        $normalized = strtr($normalized, [
            'ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i',
            'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
        ]);

        if (str_starts_with($normalized, 'refund')
            || str_contains($normalized, '/refund')
            || preg_match('/^grazinim\w*\/refund\b/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^pav(?:\s*\(\s*refund\s*\))?$/u', $normalized) === 1) {
            return true;
        }

        if ($normalized === 'pavedimas') {
            return true;
        }

        if (preg_match('/^lesu\s+grazinimas(?:\s*\(\s*refund\s*\))?$/u', $normalized) === 1) {
            return true;
        }

        return false;
    }

    public static function extractRefundInvoiceNumber(?string ...$parts): ?string
    {
        $haystack = trim(implode(' ', array_filter($parts, fn ($part): bool => filled($part))));
        if ($haystack === '') {
            return null;
        }

        if (preg_match('/(?:gr[aą]žinim\w*\/|grazinim\w*\/)?refund\s+(\d{6,})/iu', $haystack, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Best counterpart name for display/storage. Keeps a good name; replaces remittance-only labels.
     */
    public static function resolve(
        ?string $counterpartName,
        ?string $description = null,
        ?string $endToEndId = null,
        ?BankStatementTransaction $transaction = null,
    ): ?string {
        $cleaned = BankStatementCounterpartCleaner::clean($counterpartName)
            ?? (filled($counterpartName) ? trim((string) $counterpartName) : null);

        if ($cleaned !== null && ! self::isRemittanceOnly($cleaned)) {
            // Mixed "Company … refund" — cleaner may already have stripped remittance.
            return $cleaned;
        }

        if ($transaction !== null) {
            $fromLinked = self::nameFromLinkedInvoices($transaction);
            if ($fromLinked !== null) {
                return $fromLinked;
            }
        }

        $invoiceNumber = self::extractRefundInvoiceNumber($counterpartName, $description, $endToEndId, $cleaned);
        if ($invoiceNumber !== null) {
            $fromInvoice = self::nameFromInvoiceNumber($invoiceNumber);
            if ($fromInvoice !== null) {
                return $fromInvoice;
            }
        }

        if ($transaction !== null
            && self::shouldInferNearbyMerchant($counterpartName, $description, $cleaned)) {
            $nearby = self::nameFromNearbyMerchant($transaction);
            if ($nearby !== null) {
                return $nearby;
            }
        }

        return $cleaned;
    }

    private static function shouldInferNearbyMerchant(?string ...$parts): bool
    {
        foreach ($parts as $part) {
            if (! filled($part)) {
                continue;
            }

            $normalized = mb_strtolower(trim((string) $part));
            $normalized = strtr($normalized, [
                'ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i',
                'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
            ]);
            $normalized = preg_replace('/^\s*[^\s·•]+\s*[·•]\s*/u', '', $normalized) ?? $normalized;
            $normalized = trim($normalized);

            if (str_starts_with($normalized, 'refund')
                || str_contains($normalized, '/refund')
                || preg_match('/^pav\s*\(\s*refund\s*\)$/u', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function nameFromLinkedInvoices(BankStatementTransaction $transaction): ?string
    {
        $transaction->loadMissing(['invoice.contact', 'invoices.contact']);

        $invoices = $transaction->relationLoaded('invoices') && $transaction->invoices->isNotEmpty()
            ? $transaction->invoices
            : collect([$transaction->invoice])->filter();

        foreach ($invoices as $invoice) {
            if (! $invoice instanceof Invoice) {
                continue;
            }

            $name = trim((string) ($invoice->contact?->company_name ?? ''));
            if ($name === '') {
                continue;
            }

            return BankStatementCounterpartCleaner::clean($name) ?? $name;
        }

        return null;
    }

    public static function nameFromInvoiceNumber(string $invoiceNumber): ?string
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            return null;
        }

        $invoice = Invoice::query()
            ->with('contact')
            ->where('invoice_number', $invoiceNumber)
            ->first()
            ?? Invoice::query()
                ->with('contact')
                ->where('invoice_number', 'like', $invoiceNumber.'%')
                ->orderBy('id')
                ->first();

        $name = trim((string) ($invoice?->contact?->company_name ?? ''));

        return $name !== '' ? (BankStatementCounterpartCleaner::clean($name) ?? $name) : null;
    }

    /**
     * When Excel only has "pav (refund)", reuse a same-amount merchant from a nearby card/expense line.
     */
    public static function nameFromNearbyMerchant(BankStatementTransaction $transaction): ?string
    {
        if (! self::isRemittanceOnly($transaction->counterpart_name)
            && ! self::isRemittanceOnly($transaction->description)) {
            return null;
        }

        $amount = round(abs((float) $transaction->amount), 2);
        if ($amount < 0.005 || $transaction->booking_date === null) {
            return null;
        }

        $booking = $transaction->booking_date instanceof Carbon
            ? $transaction->booking_date->copy()
            : Carbon::parse((string) $transaction->booking_date);

        $candidates = BankStatementTransaction::query()
            ->whereKeyNot($transaction->getKey())
            ->whereDate('booking_date', '>=', $booking->copy()->subDays(1)->toDateString())
            ->whereDate('booking_date', '<=', $booking->copy()->addDays(1)->toDateString())
            ->whereRaw('ABS(amount - ?) < 0.005', [$amount])
            ->whereNotNull('counterpart_name')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'counterpart_name', 'description', 'account_iban', 'booking_date'])
            ->sortBy(function (BankStatementTransaction $candidate) use ($booking): int {
                $date = $candidate->booking_date instanceof Carbon
                    ? $candidate->booking_date
                    : Carbon::parse((string) $candidate->booking_date);

                return abs($booking->diffInDays($date, false));
            })
            ->values();

        $ownCompanyNeedles = ['futura textiles', 'uab futura'];

        foreach ($candidates as $candidate) {
            $name = BankStatementCounterpartCleaner::clean($candidate->counterpart_name)
                ?? trim((string) $candidate->counterpart_name);
            if ($name === '' || self::isRemittanceOnly($name)) {
                continue;
            }
            if (BankStatementDirectionResolver::isGenericOwnTransferLabel($name)) {
                continue;
            }
            if (BankStatementDirectionResolver::isBankServiceFee($name, $candidate->description)) {
                continue;
            }

            $nameLower = mb_strtolower($name);
            $skip = false;
            foreach ($ownCompanyNeedles as $needle) {
                if (str_contains($nameLower, $needle)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            if (preg_match('/\b(income|expense|credit card)\b/iu', $name) === 1) {
                continue;
            }

            return $name;
        }

        return null;
    }
}
