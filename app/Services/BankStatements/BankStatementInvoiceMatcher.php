<?php

namespace App\Services\BankStatements;

use App\Models\BankStatementTransaction;
use App\Models\Currency;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class BankStatementInvoiceMatcher
{
    public const DATE_WINDOW_BEFORE_DAYS = 400;

    public const DATE_WINDOW_AFTER_DAYS = 30;

    /**
     * Batch invoice ids keyed by transaction id, filled by the latest suggestFor() call.
     *
     * @var array<int, list<int>>
     */
    private static array $batchInvoiceIdsByTransaction = [];

    /**
     * @param  Collection<int, BankStatementTransaction>  $transactions
     * @return Collection<int, Invoice> keyed by transaction id (primary invoice; see batchInvoicesFor)
     */
    public static function suggestFor(Collection $transactions): Collection
    {
        self::$batchInvoiceIdsByTransaction = [];

        $unlinked = $transactions
            ->filter(fn (BankStatementTransaction $row): bool => ! $row->isResolved())
            ->values();

        if ($unlinked->isEmpty()) {
            return collect();
        }

        $invoices = self::candidateInvoices($unlinked);
        if ($invoices->isEmpty()) {
            return collect();
        }

        $settledInvoiceIds = $invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->isFullyPaid())
            ->pluck('id')
            ->all();
        $settledInvoiceIds = array_fill_keys($settledInvoiceIds, true);
        $usedInvoices = $settledInvoiceIds;

        $suggestions = collect();

        // Prefer exact remittance batches (comma-separated invoice numbers summing to payment).
        foreach ($unlinked as $transaction) {
            $batch = self::findExactRemittanceBatch($transaction, $invoices, $usedInvoices);
            if ($batch === null || $batch->count() < 2) {
                continue;
            }

            $primary = $batch->sortByDesc(fn (Invoice $invoice): float => (float) $invoice->sum_inc_vat)->first();
            if (! $primary instanceof Invoice) {
                continue;
            }

            $suggestions->put($transaction->getKey(), $primary);
            self::$batchInvoiceIdsByTransaction[(int) $transaction->getKey()] = $batch
                ->map(fn (Invoice $invoice): int => (int) $invoice->getKey())
                ->values()
                ->all();

            foreach ($batch as $invoice) {
                $usedInvoices[(int) $invoice->getKey()] = true;
            }
        }

        // Remittance lists several invoice numbers — suggest every invoice found in the
        // system even when totals do not match yet (missing invoice, partial payment, etc.).
        foreach ($unlinked as $transaction) {
            if ($suggestions->has($transaction->getKey())) {
                continue;
            }

            $batch = self::findRemittanceNumberBatch($transaction, $invoices, $usedInvoices);
            if ($batch === null || $batch->count() < 2) {
                continue;
            }

            $primary = $batch->sortByDesc(fn (Invoice $invoice): float => (float) $invoice->sum_inc_vat)->first();
            if (! $primary instanceof Invoice) {
                continue;
            }

            $suggestions->put($transaction->getKey(), $primary);
            self::$batchInvoiceIdsByTransaction[(int) $transaction->getKey()] = $batch
                ->map(fn (Invoice $invoice): int => (int) $invoice->getKey())
                ->values()
                ->all();

            foreach ($batch as $invoice) {
                $usedInvoices[(int) $invoice->getKey()] = true;
            }
        }

        // Advanced mapping: same customer/supplier, several unpaid invoices whose
        // outstanding amounts uniquely sum to the bank payment (no invoice #s needed).
        foreach ($unlinked as $transaction) {
            if ($suggestions->has($transaction->getKey())) {
                continue;
            }

            $batch = self::findExactAmountCombinationBatch($transaction, $invoices, $usedInvoices);
            if ($batch === null || $batch->count() < 2) {
                continue;
            }

            $primary = $batch->sortByDesc(fn (Invoice $invoice): float => (float) $invoice->sum_inc_vat)->first();
            if (! $primary instanceof Invoice) {
                continue;
            }

            $suggestions->put($transaction->getKey(), $primary);
            self::$batchInvoiceIdsByTransaction[(int) $transaction->getKey()] = $batch
                ->map(fn (Invoice $invoice): int => (int) $invoice->getKey())
                ->values()
                ->all();

            foreach ($batch as $invoice) {
                $usedInvoices[(int) $invoice->getKey()] = true;
            }
        }

        $pairs = [];

        foreach ($unlinked as $transaction) {
            if ($suggestions->has($transaction->getKey())) {
                continue;
            }

            $ranked = [];

            foreach ($invoices as $invoice) {
                if (isset($usedInvoices[(int) $invoice->getKey()])) {
                    continue;
                }

                $score = self::score($transaction, $invoice);
                if ($score === null) {
                    continue;
                }

                $ranked[] = [
                    'invoice' => $invoice,
                    'score' => $score,
                ];
            }

            usort($ranked, function (array $left, array $right) use ($transaction): int {
                $scoreCmp = $right['score'] <=> $left['score'];
                if ($scoreCmp !== 0) {
                    return $scoreCmp;
                }

                $daysLeft = self::dateDistanceDays($transaction, $left['invoice']) ?? 9999;
                $daysRight = self::dateDistanceDays($transaction, $right['invoice']) ?? 9999;
                if ($daysLeft !== $daysRight) {
                    return $daysLeft <=> $daysRight;
                }

                // Prefer unpaid / less-paid when still tied.
                $paidLeft = round((float) ($left['invoice']->paid_amount ?? 0), 2);
                $paidRight = round((float) ($right['invoice']->paid_amount ?? 0), 2);

                return $paidLeft <=> $paidRight;
            });

            if ($ranked === []) {
                continue;
            }

            $best = $ranked[0];
            $runnerUp = $ranked[1] ?? null;
            if ($runnerUp !== null && $runnerUp['score'] === $best['score']) {
                $bestDays = self::dateDistanceDays($transaction, $best['invoice']) ?? 9999;
                $runnerDays = self::dateDistanceDays($transaction, $runnerUp['invoice']) ?? 9999;
                if ($bestDays === $runnerDays) {
                    // Truly ambiguous — do not auto-suggest.
                    continue;
                }
            }

            $pairs[] = [
                'transaction_id' => (int) $transaction->getKey(),
                'invoice' => $best['invoice'],
                'score' => $best['score'],
            ];
        }

        usort($pairs, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        foreach ($pairs as $pair) {
            $invoiceId = (int) $pair['invoice']->getKey();
            if (isset($usedInvoices[$invoiceId]) || $suggestions->has($pair['transaction_id'])) {
                continue;
            }

            $suggestions->put($pair['transaction_id'], $pair['invoice']);
            $usedInvoices[$invoiceId] = true;
        }

        return $suggestions;
    }

    /**
     * @return Collection<int, Invoice>
     */
    public static function batchInvoicesFor(BankStatementTransaction $transaction): Collection
    {
        $ids = self::$batchInvoiceIdsByTransaction[(int) $transaction->getKey()] ?? null;
        if ($ids === null || $ids === []) {
            return collect();
        }

        return Invoice::query()
            ->whereIn('id', $ids)
            ->orderByDesc('sum_inc_vat')
            ->orderBy('id')
            ->get();
    }

    public static function suggestionFor(BankStatementTransaction $transaction): ?Invoice
    {
        return self::suggestFor(collect([$transaction]))->get($transaction->getKey());
    }

    /**
     * Auto-link confident matches for unlinked transactions.
     *
     * @return int Number of newly linked transactions
     */
    public static function autoLinkUnlinked(): int
    {
        $unlinked = BankStatementTransaction::query()
            ->withoutLinkedDocument()
            ->where('no_document_needed', false)
            ->where('is_historical', false)
            ->orderBy('booking_date')
            ->orderBy('id')
            ->get();

        $suggestions = self::suggestFor($unlinked);
        $linked = 0;

        foreach ($suggestions as $transactionId => $invoice) {
            $transaction = $unlinked->firstWhere('id', $transactionId);
            if ($transaction === null) {
                continue;
            }

            try {
                self::link($transaction, $invoice);
                $linked++;
            } catch (RuntimeException) {
                // Already linked or invalid — skip.
            }
        }

        return $linked;
    }

    public static function link(BankStatementTransaction $transaction, Invoice $invoice): void
    {
        if ($transaction->invoice_id) {
            throw new RuntimeException('This payment is already linked to an invoice.');
        }

        if ($transaction->payment_id) {
            throw new RuntimeException('This payment is already linked to a contract.');
        }

        $batch = self::batchInvoicesFor($transaction);
        if ($batch->isEmpty()) {
            $candidates = self::candidateInvoices(collect([$transaction]));
            $batch = self::findExactRemittanceBatch($transaction, $candidates, [])
                ?? self::findRemittanceNumberBatch($transaction, $candidates, [])
                ?? self::findExactAmountCombinationBatch($transaction, $candidates, [])
                ?? collect();
        }

        if ($batch->count() >= 2 && $batch->contains(fn (Invoice $row): bool => (int) $row->getKey() === (int) $invoice->getKey())) {
            self::linkMany($transaction, $batch);

            return;
        }

        self::linkMany($transaction, collect([$invoice]));
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    public static function linkMany(BankStatementTransaction $transaction, Collection $invoices, bool $append = false): void
    {
        if (! $append && $transaction->invoice_id) {
            throw new RuntimeException('This payment is already linked to an invoice.');
        }

        if ($transaction->payment_id) {
            throw new RuntimeException('This payment is already linked to a contract.');
        }

        if ($append) {
            $transaction->loadMissing('invoices');
            $existing = $transaction->linkedInvoices();
            $invoices = $existing
                ->concat($invoices)
                ->filter(fn (Invoice $invoice): bool => $invoice->getKey() !== null)
                ->unique(fn (Invoice $invoice): int => (int) $invoice->getKey())
                ->values();

            // Re-link from a clean pivot so amounts stay consistent.
            if ($transaction->invoice_id || $transaction->invoices()->exists()) {
                self::unlink($transaction);
                $transaction->refresh();
            }
        }

        $invoices = $invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->getKey() !== null)
            ->unique(fn (Invoice $invoice): int => (int) $invoice->getKey())
            ->values();

        if ($invoices->isEmpty()) {
            throw new RuntimeException('No invoices to link.');
        }

        $allocations = [];
        $paymentAmount = round(abs((float) $transaction->amount), 2);
        $bankCurrency = Currency::normalize($transaction->currency ?? 'EUR');
        $isBatch = $invoices->count() >= 2;
        $remainingComparable = $paymentAmount;

        foreach ($invoices as $invoice) {
            $invoice->refresh();

            $faceComparable = round(abs($invoice->comparableAmountForBank($bankCurrency)), 2);
            $outstandingComparable = round(max(0, $invoice->comparableOutstandingForBank($bankCurrency)), 2);

            // Restore/import may mark invoices paid without bank links — treat as unpaid for allocation.
            if ($outstandingComparable < 0.005 && ! $invoice->hasBankPaymentLinks()) {
                $outstandingComparable = $faceComparable;
            }

            if (! $isBatch) {
                $amount = round(max(0, $invoice->amountFromBankPayment($paymentAmount, $bankCurrency)), 2);
                $allocations[] = [
                    'invoice' => $invoice,
                    'amount' => $amount,
                    'apply' => $amount >= 0.005,
                ];
                $remainingComparable = 0.0;

                continue;
            }

            // Keep every explicitly selected invoice linked, even when already paid elsewhere.
            if ($remainingComparable < 0.005) {
                $allocations[] = [
                    'invoice' => $invoice,
                    'amount' => 0.0,
                    'apply' => false,
                ];

                continue;
            }

            $comparableWant = $outstandingComparable >= 0.005
                ? $outstandingComparable
                : $faceComparable;

            if ($comparableWant < 0.005) {
                $allocations[] = [
                    'invoice' => $invoice,
                    'amount' => 0.0,
                    'apply' => false,
                ];

                continue;
            }

            $takeComparable = min($comparableWant, $remainingComparable);

            if ($outstandingComparable >= 0.005) {
                $amount = $invoice->amountFromBankPayment($takeComparable, $bankCurrency);
                $apply = true;
            } else {
                // Already covered by another payment — still attach for document matching.
                $amount = $invoice->convertBankAmountToInvoiceCurrency($takeComparable, $bankCurrency);
                $apply = false;
            }

            $amount = round(max(0, $amount), 2);
            $allocations[] = [
                'invoice' => $invoice,
                'amount' => $amount,
                'apply' => $apply && $amount >= 0.005,
            ];
            $remainingComparable = round($remainingComparable - $takeComparable, 2);
        }

        if ($allocations === []) {
            throw new RuntimeException('No invoices to link.');
        }

        $primary = collect($allocations)
            ->sortByDesc(fn (array $row): float => $row['amount'])
            ->first()['invoice'];

        $transaction->update([
            'invoice_id' => $primary->getKey(),
            'payment_id' => null,
            'employee_monthly_payment_id' => null,
            'document_id' => null,
            'business_trip_request_id' => null,
        ]);

        $sync = [];
        foreach ($allocations as $row) {
            /** @var Invoice $invoice */
            $invoice = $row['invoice'];
            $amount = round((float) $row['amount'], 2);

            if ($row['apply']) {
                $beforePaid = round((float) ($invoice->paid_amount ?? 0), 2);
                $invoice->applyBankPaymentAmount($amount);
                $invoice->markPaidIfCoveredByBankPayment($paymentAmount, $bankCurrency);
                $invoice->refresh();
                $applied = max(0, round((float) ($invoice->paid_amount ?? 0) - $beforePaid, 2));
                $sync[(int) $invoice->getKey()] = ['amount' => $applied > 0 ? $applied : $amount];
            } else {
                $sync[(int) $invoice->getKey()] = ['amount' => $amount];
            }

            $invoice->applySuggestedCategoryIfMissing();
        }

        $transaction->invoices()->sync($sync);

        self::backfillCounterpartFromInvoices($transaction->fresh(['invoice.contact', 'invoices.contact']) ?? $transaction);
    }

    public static function backfillCounterpartFromInvoices(BankStatementTransaction $transaction): void
    {
        if (! BankStatementCounterpartResolver::isRemittanceOnly($transaction->counterpart_name)) {
            return;
        }

        $resolved = BankStatementCounterpartResolver::resolve(
            $transaction->counterpart_name,
            $transaction->description,
            $transaction->end_to_end_id,
            $transaction,
        );

        if ($resolved === null || $resolved === $transaction->counterpart_name) {
            return;
        }

        $transaction->forceFill([
            'counterpart_name' => $resolved,
        ])->save();
    }

    public static function unlink(BankStatementTransaction $transaction): void
    {
        if (! $transaction->invoice_id && ! $transaction->invoices()->exists()) {
            return;
        }

        $transaction->loadMissing('invoices');
        $pivotRows = $transaction->invoices;

        if ($pivotRows->isNotEmpty()) {
            $affected = $pivotRows->values();
            $transaction->invoices()->detach();
            $transaction->update(['invoice_id' => null]);

            foreach ($affected as $invoice) {
                $invoice->unsetRelation('bankStatementTransactionLinks');
                $invoice->unsetRelation('bankStatementTransactions');

                if ($invoice->hasBankPaymentLinks()) {
                    $invoice->resyncPaymentFromBankLinks();
                } else {
                    $amount = round((float) ($invoice->pivot->amount ?? 0), 2);
                    if ($amount > 0) {
                        $invoice->reverseBankPaymentAmount($amount);
                    }
                }
            }

            return;
        }

        $invoice = $transaction->invoice ?? Invoice::query()->find($transaction->invoice_id);
        $amount = (float) $transaction->amount;

        $transaction->update(['invoice_id' => null]);

        if ($invoice instanceof Invoice) {
            if ($invoice->hasBankPaymentLinks()) {
                $invoice->resyncPaymentFromBankLinks();
            } else {
                $invoice->reverseBankPaymentAmount(
                    $invoice->amountFromBankPayment($amount, Currency::normalize($transaction->currency ?? 'EUR')),
                );
            }
        }
    }

    /**
     * Unlink a single invoice from a payment. If it was the only link, clears the payment fully.
     */
    public static function unlinkInvoice(BankStatementTransaction $transaction, Invoice $invoice): void
    {
        $transaction->loadMissing('invoices');

        $linkedIds = $transaction->invoices
            ->pluck('id')
            ->merge(collect([$transaction->invoice_id])->filter())
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($linkedIds->isEmpty()) {
            return;
        }

        if ($linkedIds->count() <= 1 || ! $linkedIds->contains((int) $invoice->getKey())) {
            if ($linkedIds->contains((int) $invoice->getKey()) || (int) $transaction->invoice_id === (int) $invoice->getKey()) {
                self::unlink($transaction);
            }

            return;
        }

        $pivotRow = $transaction->invoices->firstWhere('id', $invoice->getKey());
        $amount = round((float) ($pivotRow?->pivot?->amount ?? 0), 2);

        $transaction->invoices()->detach($invoice->getKey());

        if ((int) $transaction->invoice_id === (int) $invoice->getKey()) {
            $next = $transaction->invoices()
                ->where('invoices.id', '!=', $invoice->getKey())
                ->orderByDesc('sum_inc_vat')
                ->first();
            $transaction->update(['invoice_id' => $next?->getKey()]);
        }

        $invoice->unsetRelation('bankStatementTransactionLinks');
        $invoice->unsetRelation('bankStatementTransactions');

        if ($invoice->hasBankPaymentLinks()) {
            $invoice->resyncPaymentFromBankLinks();
        } elseif ($amount > 0) {
            $invoice->reverseBankPaymentAmount($amount);
        }
    }

    public static function transactionPickerLabel(BankStatementTransaction $transaction): string
    {
        return implode(' · ', array_values(array_filter([
            optional($transaction->booking_date)->format('Y-m-d'),
            filled($transaction->counterpart_name) ? (string) $transaction->counterpart_name : null,
            Money::format(abs((float) $transaction->amount), 2, Currency::normalize($transaction->currency ?? 'EUR')),
            filled($transaction->description)
                ? Str::limit(trim((string) $transaction->description), 48)
                : null,
        ], fn (mixed $value): bool => filled($value))));
    }

    /**
     * @return array<int, string>
     */
    public static function searchUnlinkedTransactions(string $search, ?Invoice $forInvoice = null, int $limit = 40): array
    {
        $search = trim($search);
        $query = BankStatementTransaction::query()
            ->withoutLinkedDocument()
            ->where('no_document_needed', false)
            ->where('is_historical', false)
            ->whereDoesntHave('invoices')
            ->orderByDesc('booking_date')
            ->orderByDesc('id');

        if ($forInvoice?->invoice_date !== null) {
            $from = $forInvoice->invoice_date->copy()->subDays(self::DATE_WINDOW_AFTER_DAYS);
            $until = $forInvoice->invoice_date->copy()->addDays(self::DATE_WINDOW_BEFORE_DAYS);
            $query->whereBetween('booking_date', [$from->toDateString(), $until->toDateString()]);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $amount = self::parseSearchAmount($search);

            $query->where(function ($builder) use ($like, $search, $amount): void {
                $builder
                    ->where('counterpart_name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('id', $search);

                if ($amount !== null) {
                    $builder->orWhereRaw('ABS(ABS(COALESCE(amount, 0)) - ?) < 0.015', [$amount]);
                }
            });

            if ($amount !== null) {
                $query->reorder()
                    ->orderByRaw('CASE WHEN ABS(ABS(COALESCE(amount, 0)) - ?) < 0.015 THEN 0 ELSE 1 END', [$amount])
                    ->orderByDesc('booking_date')
                    ->orderByDesc('id');
            }
        }

        return $query
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (BankStatementTransaction $transaction): array => [
                $transaction->getKey() => self::transactionPickerLabel($transaction),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function pickerOptionsForInvoice(Invoice $invoice, int $limit = 25): array
    {
        $suggested = self::suggestTransactionsForInvoice($invoice, $limit);
        if ($suggested->isNotEmpty()) {
            return $suggested
                ->mapWithKeys(fn (BankStatementTransaction $transaction): array => [
                    $transaction->getKey() => self::transactionPickerLabel($transaction),
                ])
                ->all();
        }

        return self::searchUnlinkedTransactions('', $invoice, $limit);
    }

    /**
     * Unlinked bank lines that the matcher would suggest for this invoice.
     *
     * @return Collection<int, BankStatementTransaction>
     */
    public static function suggestTransactionsForInvoice(Invoice $invoice, int $limit = 20): Collection
    {
        if ($invoice->invoice_date === null) {
            return collect();
        }

        $from = $invoice->invoice_date->copy()->subDays(self::DATE_WINDOW_AFTER_DAYS);
        $until = $invoice->invoice_date->copy()->addDays(self::DATE_WINDOW_BEFORE_DAYS);

        $candidates = BankStatementTransaction::query()
            ->withoutLinkedDocument()
            ->where('no_document_needed', false)
            ->where('is_historical', false)
            ->whereDoesntHave('invoices')
            ->whereBetween('booking_date', [$from->toDateString(), $until->toDateString()])
            ->orderByDesc('booking_date')
            ->limit(250)
            ->get();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $suggestions = self::suggestFor($candidates);

        return $suggestions
            ->filter(fn (Invoice $suggested): bool => (int) $suggested->getKey() === (int) $invoice->getKey())
            ->keys()
            ->map(fn ($transactionId): ?BankStatementTransaction => $candidates->firstWhere('id', (int) $transactionId))
            ->filter()
            ->take($limit)
            ->values();
    }

    public static function pickerLabel(Invoice $invoice): string
    {
        $invoice->loadMissing(['contact', 'order.user']);

        $customer = self::invoiceCustomerNames($invoice)[0] ?? $invoice->contact?->company_name;

        return implode(' · ', array_filter([
            $invoice->displayNumber(),
            optional($invoice->invoice_date)->format('Y-m-d'),
            $customer,
            Money::format((float) $invoice->sum_inc_vat, 2, $invoice->currencyCode()),
            ! $invoice->isEuroCurrency()
                ? Money::format($invoice->amountInEur(), 2, 'EUR')
                : null,
            $invoice->paymentSettlementLabel(),
            $invoice->isPartlyPaid()
                ? 'left '.Money::format($invoice->outstandingAmount(), 2, $invoice->currencyCode())
                : null,
        ], fn (mixed $value): bool => filled($value)));
    }

    /**
     * @return array<int, string>
     */
    public static function searchInvoices(string $search, int $limit = 40): array
    {
        $search = trim($search);
        $query = Invoice::query()
            ->with(['contact', 'order.user'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $amount = self::parseSearchAmount($search);

            $query->where(function ($builder) use ($like, $search, $amount): void {
                $builder
                    ->where('invoice_number', 'like', $like)
                    ->orWhere('id', $search)
                    ->orWhereHas('contact', function ($contactQuery) use ($like): void {
                        $contactQuery->where('company_name', 'like', $like);
                    })
                    ->orWhereHas('order.user', function ($userQuery) use ($like): void {
                        $userQuery
                            ->where('company_name', 'like', $like)
                            ->orWhere('name', 'like', $like);
                    });

                if ($amount !== null) {
                    $builder
                        ->orWhereRaw('ABS(COALESCE(sum_inc_vat, 0) - ?) < 0.015', [$amount])
                        ->orWhereRaw('ABS(COALESCE(sum_inc_vat_eur, -999999) - ?) < 0.015', [$amount])
                        ->orWhereRaw(
                            'ABS((CASE WHEN COALESCE(sum_inc_vat, 0) - COALESCE(paid_amount, 0) > 0 THEN COALESCE(sum_inc_vat, 0) - COALESCE(paid_amount, 0) ELSE 0 END) - ?) < 0.015',
                            [$amount],
                        );
                }
            });

            if ($amount !== null) {
                $query->reorder()
                    ->orderByRaw('CASE WHEN ABS(COALESCE(sum_inc_vat, 0) - ?) < 0.015 THEN 0 ELSE 1 END', [$amount])
                    ->orderByDesc('invoice_date')
                    ->orderByDesc('id');
            }
        }

        return $query
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Invoice $invoice): array => [
                $invoice->getKey() => self::pickerLabel($invoice),
            ])
            ->all();
    }

    /**
     * Parse a typed search string into a money amount when it looks numeric.
     * Accepts 73.29, 73,29, 1 502.87, €18, etc.
     */
    private static function parseSearchAmount(string $search): ?float
    {
        $normalized = trim(preg_replace('/[€$£]/u', '', $search) ?? '');
        $normalized = trim(str_replace("\u{00A0}", ' ', $normalized));

        if ($normalized === '' || preg_match('/[^0-9.,\s]/u', $normalized) === 1) {
            return null;
        }

        $compact = str_replace(' ', '', $normalized);
        if ($compact === '' || preg_match('/[^0-9.,]/', $compact) === 1) {
            return null;
        }

        if (str_contains($compact, ',') && str_contains($compact, '.')) {
            if (strrpos($compact, ',') > strrpos($compact, '.')) {
                // 1.502,87
                $compact = str_replace('.', '', $compact);
                $compact = str_replace(',', '.', $compact);
            } else {
                // 1,502.87
                $compact = str_replace(',', '', $compact);
            }
        } elseif (str_contains($compact, ',')) {
            if (preg_match('/,\d{1,2}$/', $compact) === 1) {
                // 73,29
                $compact = str_replace(',', '.', $compact);
            } else {
                $compact = str_replace(',', '', $compact);
            }
        }

        if (! is_numeric($compact)) {
            return null;
        }

        return round((float) $compact, 2);
    }

    /**
     * @return array<int, string>
     */
    public static function pickerOptionsFor(BankStatementTransaction $transaction): array
    {
        $amount = round((float) $transaction->amount, 2);
        $booking = $transaction->booking_date ?? now();

        $options = Invoice::query()
            ->with(['contact', 'order.user'])
            ->where(function ($query) use ($amount): void {
                $query
                    ->whereRaw('ABS(sum_inc_vat - ?) < 0.005', [$amount])
                    ->orWhereRaw('ABS(COALESCE(sum_inc_vat_eur, sum_inc_vat) - ?) < 0.005', [$amount])
                    ->orWhereRaw(
                        'ABS((CASE WHEN sum_inc_vat - COALESCE(paid_amount, 0) > 0 THEN sum_inc_vat - COALESCE(paid_amount, 0) ELSE 0 END) - ?) < 0.005',
                        [$amount],
                    );
            })
            ->where(function ($query): void {
                $query
                    ->where('paid', false)
                    ->orWhereNull('paid');
            })
            ->whereBetween('invoice_date', [
                $booking->copy()->subDays(self::DATE_WINDOW_BEFORE_DAYS)->toDateString(),
                $booking->copy()->addDays(self::DATE_WINDOW_AFTER_DAYS)->toDateString(),
            ])
            ->orderByDesc('invoice_date')
            ->limit(40)
            ->get()
            ->filter(function (Invoice $invoice) use ($amount, $transaction): bool {
                $bankCurrency = Currency::normalize($transaction->currency ?? 'EUR');

                return abs(abs($invoice->comparableAmountForBank($bankCurrency)) - $amount) < 0.05
                    || abs($invoice->comparableOutstandingForBank($bankCurrency) - $amount) < 0.05
                    || abs(abs((float) $invoice->sum_inc_vat) - $amount) < 0.005;
            })
            ->values();

        $suggested = self::suggestionFor($transaction);
        if ($suggested !== null && ! $options->contains(fn (Invoice $invoice): bool => (int) $invoice->getKey() === (int) $suggested->getKey())) {
            $options->prepend($suggested);
        }

        return $options
            ->mapWithKeys(fn (Invoice $invoice): array => [
                $invoice->getKey() => self::pickerLabel($invoice),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, BankStatementTransaction>  $transactions
     * @return Collection<int, Invoice>
     */
    private static function candidateInvoices(Collection $transactions): Collection
    {
        $dates = $transactions
            ->map(fn (BankStatementTransaction $row) => $row->booking_date)
            ->filter();

        $query = Invoice::query()->with(['contact', 'order.user']);

        if ($dates->isNotEmpty()) {
            $from = $dates->min()->copy()->subDays(self::DATE_WINDOW_BEFORE_DAYS)->toDateString();
            $to = $dates->max()->copy()->addDays(self::DATE_WINDOW_AFTER_DAYS)->toDateString();
            $query->whereBetween('invoice_date', [$from, $to]);
        }

        $invoices = $query->get()->filter(function (Invoice $invoice): bool {
            // Keep numberless invoices — they can still match on supplier + amount + date.
            if (! filled(trim((string) ($invoice->invoice_number ?? '')))) {
                return true;
            }

            return self::isPlausibleInvoiceNumber($invoice->invoice_number);
        })->values();

        $mentioned = [];
        foreach ($transactions as $transaction) {
            foreach (self::invoiceNumbersInText(self::haystack($transaction)) as $number) {
                $mentioned[Str::upper($number)] = true;
            }
            foreach (self::remittanceBatchNumbers(self::remittanceBatchHaystack($transaction)) as $number) {
                $mentioned[Str::upper($number)] = true;
            }
        }

        if ($mentioned !== []) {
            $numbers = array_keys($mentioned);
            $extra = Invoice::query()
                ->with(['contact', 'order.user'])
                ->whereNotNull('invoice_number')
                ->where(function ($builder) use ($numbers): void {
                    $builder->whereIn('invoice_number', $numbers);
                    foreach ($numbers as $number) {
                        if (preg_match('/^\d{3,6}$/', $number) === 1) {
                            $builder->orWhere('invoice_number', 'like', '%'.$number);
                        }
                        // FTE0112 ↔ padded / unpadded variants already in $numbers;
                        // also match stored numbers ending with the digit group.
                        if (preg_match('/^(?:FTE|ITC|INV|FT)0*([0-9]{3,6})$/i', $number, $match) === 1) {
                            $digits = $match[1];
                            $builder->orWhere('invoice_number', 'like', '%'.$digits);
                        }
                    }
                })
                ->get()
                ->filter(fn (Invoice $invoice): bool => self::isPlausibleInvoiceNumber($invoice->invoice_number));

            $invoices = $invoices
                ->concat($extra)
                ->unique(fn (Invoice $invoice): int => (int) $invoice->getKey())
                ->values();
        }

        return $invoices;
    }

    private static function score(BankStatementTransaction $transaction, Invoice $invoice): ?int
    {
        $amount = round(abs((float) $transaction->amount), 2);
        $bankCurrency = Currency::normalize($transaction->currency ?? 'EUR');
        $invoiceAmount = abs($invoice->comparableAmountForBank($bankCurrency));
        $outstanding = $invoice->comparableOutstandingForBank($bankCurrency);
        $rawInvoiceAmount = abs(round((float) ($invoice->sum_inc_vat ?? 0), 2));
        $amountMatchesFull = abs($amount - $invoiceAmount) < 0.005;
        $amountMatchesOutstanding = $outstanding > 0.004 && abs($amount - $outstanding) < 0.005;
        // Face-value match (e.g. foreign-currency invoice paid as the same numeric sum).
        $amountMatchesRaw = abs($amount - $rawInvoiceAmount) < 0.005;
        $amountMatches = $amountMatchesFull || $amountMatchesOutstanding || $amountMatchesRaw;
        $haystack = self::haystack($transaction);
        $numberInText = self::invoiceNumberAppears($invoice, $haystack);
        $customerMatches = self::customerMatches($transaction, $invoice, $haystack);
        $days = self::dateDistanceDays($transaction, $invoice);
        $hasPlausibleNumber = self::isPlausibleInvoiceNumber($invoice->invoice_number);

        // Raw face-value matches without FX conversion need a supplier match to avoid
        // coincidental number collisions across currencies.
        if ($amountMatchesRaw && ! $amountMatchesFull && ! $amountMatchesOutstanding && ! $customerMatches && ! $numberInText) {
            $amountMatches = false;
        }

        // Amount + same supplier/customer + nearby dates is enough even without an invoice #.
        $strongPartyAmountMatch = $amountMatches
            && $customerMatches
            && $days !== null
            && $days <= 45;

        if (! $hasPlausibleNumber && ! $strongPartyAmountMatch) {
            return null;
        }

        // Comment/counterpart points at a specific invoice # — do not match a different
        // invoice on amount/customer alone (avoids false outstanding-balance hits).
        // Exception: invoices with no stored number can't verify remittance tokens; allow
        // strong amount+customer matches so OCR-missing numbers still link.
        if (self::hasExplicitInvoiceReference($haystack) && ! $numberInText) {
            if ($hasPlausibleNumber || ! $strongPartyAmountMatch) {
                return null;
            }
        }

        if (! $amountMatches && ! $numberInText) {
            return null;
        }

        $score = 0;

        if ($amountMatchesOutstanding && ! $amountMatchesFull) {
            $score += 55;
        } elseif ($amountMatchesFull) {
            $score += 50;
        } elseif ($amountMatchesRaw) {
            $score += 50;
        }

        if ($numberInText) {
            $score += 40;
        }

        if ($customerMatches) {
            $score += 30;
        }

        if ($days !== null) {
            $score += match (true) {
                $days === 0 => 20,
                $days <= 2 => 16,
                $days <= 5 => 13,
                $days <= 7 => 10,
                $days <= 14 => 8,
                $days <= 21 => 6,
                $days <= 45 => 4,
                $days <= 120 => 2,
                $days <= 400 => 1,
                default => 0,
            };
        }

        // Same supplier + exact sum + close dates is a high-confidence suggestion.
        if ($strongPartyAmountMatch && $days <= 7) {
            $score += 15;
        } elseif ($strongPartyAmountMatch) {
            $score += 8;
        }

        // Amount alone is not enough — need customer, invoice #, or a close date.
        $hasSupport = $numberInText || $customerMatches || ($days !== null && $days <= 14);
        if ($amountMatches && ! $hasSupport) {
            return null;
        }

        // Without an invoice # in the remittance, avoid invoices dated long after the
        // payment — unless the supplier matches (card/subscription bills often follow).
        if ($amountMatches && ! $numberInText
            && $transaction->booking_date !== null
            && $invoice->invoice_date !== null
            && $invoice->invoice_date->gt($transaction->booking_date)
        ) {
            $afterDays = (int) $transaction->booking_date->diffInDays($invoice->invoice_date);
            $maxAfter = $customerMatches ? 21 : 7;
            if ($afterDays > $maxAfter) {
                return null;
            }
        }

        if (! $amountMatches && (! $numberInText || mb_strlen(trim((string) $invoice->invoice_number)) < 4)) {
            return null;
        }

        // Invoice number without amount needs customer support or a very close date.
        if (! $amountMatches && $numberInText && ! $customerMatches && ($days === null || $days > 45)) {
            return null;
        }

        return $score >= 60 ? $score : null;
    }

    private static function haystack(BankStatementTransaction $transaction): string
    {
        return trim(implode(' ', array_filter([
            $transaction->counterpart_name,
            $transaction->description,
            $transaction->comment,
            $transaction->end_to_end_id,
            $transaction->account_servicer_ref,
        ], fn (mixed $value): bool => filled($value))));
    }

    /**
     * Unique combination of same-party unpaid invoices whose outstanding amounts
     * equal the bank payment. Used when remittance text has no invoice number list.
     *
     * @param  Collection<int, Invoice>  $candidates
     * @param  array<int, bool>  $usedInvoiceIds
     * @return Collection<int, Invoice>|null
     */
    private static function findExactAmountCombinationBatch(
        BankStatementTransaction $transaction,
        Collection $candidates,
        array $usedInvoiceIds,
    ): ?Collection {
        $paymentAmount = round(abs((float) $transaction->amount), 2);
        if ($paymentAmount < 1) {
            return null;
        }

        // Remittance clearly names invoice(s) — leave to number-based matchers.
        if (self::hasExplicitInvoiceReference(self::haystack($transaction))) {
            return null;
        }

        // Digit tokens that resolve to real invoice numbers → remittance batch owns this.
        $remitNumbers = self::remittanceBatchNumbers(self::remittanceBatchHaystack($transaction));
        if ($remitNumbers !== []) {
            $knownNumbers = $candidates
                ->map(fn (Invoice $invoice): string => trim((string) $invoice->invoice_number))
                ->filter()
                ->map(fn (string $number): string => Str::upper($number))
                ->all();
            $knownLookup = array_fill_keys($knownNumbers, true);
            foreach ($remitNumbers as $number) {
                if (isset($knownLookup[Str::upper($number)])) {
                    return null;
                }
            }
        }

        $bankCurrency = Currency::normalize($transaction->currency ?? 'EUR');
        $party = [];

        foreach ($candidates as $invoice) {
            $id = (int) $invoice->getKey();
            if (isset($usedInvoiceIds[$id])) {
                continue;
            }

            $orphanPaid = $invoice->isFullyPaid() && ! $invoice->hasBankPaymentLinks();
            if ($invoice->isFullyPaid() && ! $orphanPaid) {
                continue;
            }

            if (! self::customerMatches($transaction, $invoice)) {
                continue;
            }

            $outstanding = round(max(0, $invoice->comparableOutstandingForBank($bankCurrency)), 2);
            if ($outstanding < 0.005 && $orphanPaid) {
                $outstanding = round(abs($invoice->comparableAmountForBank($bankCurrency)), 2);
            }

            if ($outstanding < 0.005 || $outstanding > $paymentAmount + 0.02) {
                continue;
            }

            $days = self::dateDistanceDays($transaction, $invoice);
            if ($days === null || $days > self::DATE_WINDOW_BEFORE_DAYS) {
                continue;
            }

            $party[] = [
                'invoice' => $invoice,
                'outstanding' => $outstanding,
            ];
        }

        if (count($party) < 2) {
            return null;
        }

        // One invoice alone already equals the payment — leave it to the single-invoice scorer.
        $exactSingles = array_values(array_filter(
            $party,
            fn (array $row): bool => abs($row['outstanding'] - $paymentAmount) <= 0.02,
        ));
        if (count($exactSingles) === 1) {
            return null;
        }

        usort($party, fn (array $left, array $right): int => $right['outstanding'] <=> $left['outstanding']);
        $party = array_slice($party, 0, 14);

        $combinations = self::exactAmountSubsets($party, $paymentAmount, maxSize: 6, maxResults: 3);
        if (count($combinations) !== 1) {
            return null;
        }

        return collect($combinations[0])
            ->map(fn (array $row): Invoice => $row['invoice'])
            ->sortByDesc(fn (Invoice $invoice): float => (float) $invoice->sum_inc_vat)
            ->values();
    }

    /**
     * @param  list<array{invoice: Invoice, outstanding: float}>  $party
     * @return list<list<array{invoice: Invoice, outstanding: float}>>
     */
    private static function exactAmountSubsets(array $party, float $target, int $maxSize, int $maxResults): array
    {
        $found = [];
        $n = count($party);

        $dfs = function (int $start, float $remain, array $picked) use (
            &$dfs,
            &$found,
            $party,
            $n,
            $maxSize,
            $maxResults,
        ): void {
            if (count($found) >= $maxResults) {
                return;
            }

            if (abs($remain) <= 0.02 && count($picked) >= 2) {
                $found[] = $picked;

                return;
            }

            if ($remain < -0.02 || count($picked) >= $maxSize) {
                return;
            }

            for ($i = $start; $i < $n; $i++) {
                $outstanding = $party[$i]['outstanding'];
                if ($outstanding > $remain + 0.02) {
                    continue;
                }

                $dfs($i + 1, round($remain - $outstanding, 2), [...$picked, $party[$i]]);
            }
        };

        $dfs(0, $target, []);

        return $found;
    }

    /**
     * Exact remittance batch: invoice numbers in the payment description map to unpaid
     * invoices whose outstanding totals equal the payment amount.
     *
     * @param  Collection<int, Invoice>  $candidates
     * @param  array<int, bool>  $usedInvoiceIds
     * @return Collection<int, Invoice>|null
     */
    private static function findExactRemittanceBatch(
        BankStatementTransaction $transaction,
        Collection $candidates,
        array $usedInvoiceIds,
    ): ?Collection {
        $matched = self::matchRemittanceInvoices($transaction, $candidates, $usedInvoiceIds, requireAllNumbers: true);
        if ($matched === null || $matched->count() < 2) {
            return null;
        }

        $bankCurrency = Currency::normalize($transaction->currency ?? 'EUR');
        $comparableSum = round($matched->sum(
            function (Invoice $invoice) use ($bankCurrency): float {
                $outstanding = $invoice->comparableOutstandingForBank($bankCurrency);
                if ($outstanding >= 0.005) {
                    return $outstanding;
                }

                // Orphan “paid” invoices (no bank links) still count at face value.
                if (! $invoice->hasBankPaymentLinks()) {
                    return abs($invoice->comparableAmountForBank($bankCurrency));
                }

                return 0.0;
            },
        ), 2);
        $paymentAmount = round(abs((float) $transaction->amount), 2);

        if (abs($comparableSum - $paymentAmount) > 0.02) {
            return null;
        }

        return $matched->values();
    }

    /**
     * Remittance lists multiple invoice numbers — return every matching unpaid invoice
     * found in the system (partial lists allowed when some numbers are missing).
     *
     * @param  Collection<int, Invoice>  $candidates
     * @param  array<int, bool>  $usedInvoiceIds
     * @return Collection<int, Invoice>|null
     */
    private static function findRemittanceNumberBatch(
        BankStatementTransaction $transaction,
        Collection $candidates,
        array $usedInvoiceIds,
    ): ?Collection {
        $matched = self::matchRemittanceInvoices($transaction, $candidates, $usedInvoiceIds, requireAllNumbers: false);
        if ($matched === null || $matched->count() < 2) {
            return null;
        }

        return $matched->values();
    }

    /**
     * @param  Collection<int, Invoice>  $candidates
     * @param  array<int, bool>  $usedInvoiceIds
     * @return Collection<int, Invoice>|null
     */
    private static function matchRemittanceInvoices(
        BankStatementTransaction $transaction,
        Collection $candidates,
        array $usedInvoiceIds,
        bool $requireAllNumbers,
    ): ?Collection {
        $numbers = self::remittanceBatchNumbers(self::remittanceBatchHaystack($transaction));
        if (count($numbers) < 2) {
            return null;
        }

        $byNumber = $candidates
            ->filter(function (Invoice $invoice) use ($usedInvoiceIds): bool {
                $id = (int) $invoice->getKey();
                $orphanPaid = $invoice->isFullyPaid() && ! $invoice->hasBankPaymentLinks();

                // Remittance number lists may reclaim orphan “paid” invoices (no bank links yet).
                if ($orphanPaid) {
                    return true;
                }

                if (isset($usedInvoiceIds[$id])) {
                    return false;
                }

                return ! $invoice->isFullyPaid();
            })
            ->keyBy(fn (Invoice $invoice): string => trim((string) $invoice->invoice_number));

        $matched = collect();
        $unresolved = [];

        foreach ($numbers as $number) {
            $invoice = $byNumber->get($number);
            if (! $invoice instanceof Invoice) {
                $invoice = $candidates->first(
                    function (Invoice $row) use ($usedInvoiceIds, $number): bool {
                        if (trim((string) $row->invoice_number) !== $number) {
                            return false;
                        }

                        $orphanPaid = $row->isFullyPaid() && ! $row->hasBankPaymentLinks();
                        if ($orphanPaid) {
                            return true;
                        }

                        if (isset($usedInvoiceIds[(int) $row->getKey()])) {
                            return false;
                        }

                        return ! $row->isFullyPaid();
                    },
                );
            }

            if (! $invoice instanceof Invoice) {
                $unresolved[] = $number;

                continue;
            }

            $matched->put((int) $invoice->getKey(), $invoice);
        }

        // Ignore truncated OCR fragments that are prefixes of a resolved invoice number.
        $matchedNumbers = $matched
            ->map(fn (Invoice $invoice): string => trim((string) $invoice->invoice_number))
            ->all();
        $unresolved = array_values(array_filter(
            $unresolved,
            function (string $number) use ($matchedNumbers): bool {
                foreach ($matchedNumbers as $matchedNumber) {
                    if ($matchedNumber !== $number && str_starts_with($matchedNumber, $number)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        if ($matched->count() < 2) {
            return null;
        }

        if ($requireAllNumbers && $unresolved !== []) {
            return null;
        }

        return $matched->values();
    }

    /**
     * Remittance text used for batch invoice numbers — description/comment only.
     * Counterpart and bank refs (endToEnd/servicer) often contain unrelated digit tokens.
     */
    private static function remittanceBatchHaystack(BankStatementTransaction $transaction): string
    {
        return trim(implode(' ', array_filter([
            $transaction->description,
            $transaction->comment,
        ], fn (mixed $value): bool => filled($value))));
    }

    /**
     * @return list<string>
     */
    private static function remittanceBatchNumbers(string $haystack): array
    {
        if ($haystack === '') {
            return [];
        }

        // Explicit comma/semicolon lists: "1010885,1010918,1011087" or "999682, 1000332".
        if (preg_match_all('/(?<![0-9A-Za-z])((?:\d{4,8}\s*[,;]\s*)+\d{4,8})(?![0-9])/u', $haystack, $listMatches) > 0) {
            $fromLists = [];
            foreach ($listMatches[1] as $list) {
                foreach (preg_split('/\s*[,;]\s*/', (string) $list) ?: [] as $part) {
                    $part = trim((string) $part);
                    if (preg_match('/^\d{4,8}$/', $part) === 1) {
                        $fromLists[] = $part;
                    }
                }
            }
            $fromLists = array_values(array_unique($fromLists));
            if (count($fromLists) >= 2) {
                return $fromLists;
            }
        }

        // Bare 5+ digit tokens (DSV / transport refs), unique, longest-first de-dupe of prefixes.
        preg_match_all('/(?<![0-9])(\d{5,})(?![0-9])/', $haystack, $matches);
        $numbers = collect($matches[1] ?? [])
            ->filter(fn (string $value): bool => mb_strlen($value) >= 5)
            ->unique()
            ->sortByDesc(fn (string $value): int => mb_strlen($value))
            ->values();

        $filtered = [];
        foreach ($numbers as $number) {
            foreach ($filtered as $kept) {
                // Drop shorter tokens that are prefixes of a longer remittance token.
                if (str_starts_with($kept, $number)) {
                    continue 2;
                }
            }
            $filtered[] = $number;
        }

        if (count($filtered) >= 2) {
            return array_values($filtered);
        }

        return collect(self::invoiceNumbersInText($haystack))
            ->filter(fn (string $value): bool => preg_match('/^\d{5,}$/', $value) === 1)
            ->unique()
            ->values()
            ->all();
    }

    private static function invoiceNumberAppears(Invoice $invoice, string $haystack): bool
    {
        $number = trim((string) ($invoice->invoice_number ?? ''));
        if ($number === '' || mb_strlen($number) < 3 || $haystack === '') {
            return false;
        }

        $hay = Str::lower($haystack);
        $num = Str::lower($number);

        if (Str::contains($hay, $num)) {
            return true;
        }

        // FTE0095 / ITC002240 / INV-0095 ↔ "INVOICE NR.:0095", "Rif.:0115", "SERIJA FTE NR.: 0112"
        if (preg_match('/^(?:fte|itc|inv|ft)[-_\/]?0*([0-9]{3,8})$/i', $number, $match) === 1) {
            $digits = ltrim($match[1], '0');
            if ($digits === '') {
                return false;
            }

            $hasInvoiceCue = (bool) preg_match(
                '/\b(fte|itc|invoice|inv|serija|rif\.?|faktura|saskait|nr\.?|no\.?)\b/iu',
                $haystack,
            );

            if ($hasInvoiceCue && preg_match('/(?<![0-9])0*'.preg_quote($digits, '/').'(?![0-9])/', $hay) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when remittance clearly names an invoice (so amount-only matches must not override).
     */
    private static function hasExplicitInvoiceReference(string $haystack): bool
    {
        if ($haystack === '') {
            return false;
        }

        return preg_match(
            '/(?:'
            .'\b(?:SERIJA\s+)?FTE\b'
            .'|\bITC\b'
            .'|\bINVOICE\s+(?:NR|NO|NBR)\.?'
            .'|\bFAKTURA\b'
            .'|\bRif\.?\s*:'
            .'|\b(?:FTE|ITC|INV|FT)[-_\/]?\d{3,}'
            .'|\bITC\s*Nr\.?'
            .')/iu',
            $haystack,
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private static function invoiceNumbersInText(string $haystack): array
    {
        if ($haystack === '') {
            return [];
        }

        $expanded = collect();

        // Structured remittance phrases first.
        $patterns = [
            '/\b(?:SERIJA\s+)?FTE\s*NR\.?\s*:?\s*0*([0-9]{3,6})\b/iu' => 'FTE',
            '/\bINVOICE\s+(?:NR|NO|NBR)\.?\s*:?\s*0*([0-9]{3,6})\s*[-–]\s*0*([0-9]{3,6})\b/iu' => 'FTE_RANGE',
            '/\bINVOICE\s+(?:NR|NO|NBR)\.?\s*:?\s*0*([0-9]{3,6})\b/iu' => 'FTE',
            '/\bFAKTURA\s*:?\s*0*([0-9]{3,6})\b/iu' => 'FTE',
            '/\bRif\.?\s*:?\s*0*([0-9]{3,6})\b/iu' => 'FTE',
            '/\bITC\s*Nr\.?\s*:?\s*0*([0-9]{3,8})\b/iu' => 'ITC',
            '/\b(FTE|ITC|INV|FT)[-_\/]?0*([0-9]{3,8})\b/iu' => null,
        ];

        foreach ($patterns as $pattern => $prefix) {
            if (preg_match_all($pattern, $haystack, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                if ($prefix === 'FTE_RANGE') {
                    $from = (int) ltrim($match[1], '0');
                    $to = (int) ltrim($match[2], '0');
                    if ($from > 0 && $to >= $from && ($to - $from) <= 12) {
                        for ($n = $from; $n <= $to; $n++) {
                            foreach (self::prefixedNumberVariants('FTE', (string) $n) as $variant) {
                                $expanded->push($variant);
                            }
                        }
                    }

                    continue;
                }

                if ($prefix === null) {
                    $tokenPrefix = Str::upper($match[1]);
                    $digits = ltrim($match[2], '0');
                } else {
                    $tokenPrefix = $prefix;
                    $digits = ltrim($match[1], '0');
                }

                if ($digits === '') {
                    continue;
                }

                foreach (self::prefixedNumberVariants($tokenPrefix, $digits) as $variant) {
                    $expanded->push($variant);
                }
            }
        }

        preg_match_all('/[A-Z]{0,8}[-\\/]?[0-9]{3,}[A-Z0-9\\-\\/]*/i', $haystack, $matches);

        $numbers = collect($matches[0] ?? [])
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => mb_strlen($value) >= 3)
            ->values();

        foreach ($numbers as $number) {
            $expanded->push(Str::upper($number));
            if (preg_match('/^(?:fte|itc|inv|ft)[-_\/]?0*([0-9]{3,8})$/i', $number, $match) === 1) {
                $digits = ltrim($match[1], '0');
                $prefix = Str::upper(preg_replace('/[-_\/]?0*[0-9]+$/i', '', $number) ?? 'FTE');
                if (! in_array($prefix, ['FTE', 'ITC', 'INV', 'FT'], true)) {
                    $prefix = 'FTE';
                }
                foreach (self::prefixedNumberVariants($prefix, $digits) as $variant) {
                    $expanded->push($variant);
                }
            }
            if (preg_match('/^0*([0-9]{3,6})$/', $number, $match) === 1
                && self::hasExplicitInvoiceReference($haystack)) {
                $digits = ltrim($match[1], '0');
                foreach (self::prefixedNumberVariants('FTE', $digits) as $variant) {
                    $expanded->push($variant);
                }
                $expanded->push($match[1]);
            }
        }

        return $expanded->unique()->values()->all();
    }

    /**
     * @return list<string>
     */
    private static function prefixedNumberVariants(string $prefix, string $digits): array
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return [];
        }

        $prefix = Str::upper($prefix);
        $padded4 = str_pad($digits, 4, '0', STR_PAD_LEFT);
        $padded = str_pad($digits, max(4, mb_strlen($digits)), '0', STR_PAD_LEFT);

        return array_values(array_unique([
            $prefix.$digits,
            $prefix.$padded4,
            $prefix.$padded,
            $digits,
            $padded4,
        ]));
    }

    private static function isPlausibleInvoiceNumber(?string $number): bool
    {
        $number = trim((string) $number);
        if ($number === '' || mb_strlen($number) < 2) {
            return false;
        }

        if (preg_match('/tarp savo|tarp s[aą]vo|perk[ėe]limas|between account|between your|banko paslaug|pavedimas|pervedimas/iu', $number) === 1) {
            return false;
        }

        return true;
    }

    private static function customerMatches(
        BankStatementTransaction $transaction,
        Invoice $invoice,
        ?string $haystack = null,
    ): bool {
        $haystack ??= self::haystack($transaction);
        $counterpart = trim((string) ($transaction->counterpart_name ?? ''));
        $customerNames = self::invoiceCustomerNames($invoice);

        if ($customerNames === []) {
            return false;
        }

        foreach ($customerNames as $customerName) {
            if ($counterpart !== '' && self::namesOverlap($counterpart, $customerName)) {
                return true;
            }

            if ($haystack !== '' && self::namesOverlap($haystack, $customerName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefer the order customer; always include invoice contact (supplier/buyer).
     *
     * @return list<string>
     */
    private static function invoiceCustomerNames(Invoice $invoice): array
    {
        $invoice->loadMissing(['order.user', 'contact']);

        $names = [];
        if ($invoice->order?->user !== null) {
            $names[] = (string) ($invoice->order->user->company_name ?? '');
            $names[] = (string) ($invoice->order->user->name ?? '');
        }

        if ($invoice->contact !== null) {
            $names[] = (string) ($invoice->contact->company_name ?? '');
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (string $name): string => trim($name), $names),
            static fn (string $name): bool => $name !== '',
        )));
    }

    private static function namesOverlap(string $left, string $right): bool
    {
        $a = self::normalizePartyName($left);
        $b = self::normalizePartyName($right);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b || Str::contains($a, $b) || Str::contains($b, $a)) {
            return true;
        }

        $tokensA = array_values(array_filter(
            explode(' ', $a),
            static fn (string $token): bool => mb_strlen($token) >= 3,
        ));
        $tokensB = array_values(array_filter(
            explode(' ', $b),
            static fn (string $token): bool => mb_strlen($token) >= 3,
        ));

        if ($tokensA === [] || $tokensB === []) {
            return false;
        }

        $meaningful = static fn (string $token): bool => ! in_array($token, [
            'the', 'and', 'for', 'group', 'payment', 'invoice', 'uab', 'pbc',
        ], true);

        foreach ($tokensA as $token) {
            if (! $meaningful($token)) {
                continue;
            }
            if (in_array($token, $tokensB, true)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePartyName(string $name): string
    {
        $name = Str::lower($name);
        $name = str_replace(['"', "'", '„', '“', '”', '`'], '', $name);
        $name = preg_replace(
            '/\b(uab|ab|mbh|kft|s\.?r\.?o\.?|d\.?o\.?o\.?|ltd|s\.?a\.?|lda|oy|co|inc|gmbh|pbc|atsiskaitymas|apmokejimas|apmokėjimas|payment|for|goods|after|invoice|invoices|nr|no|1st|2nd|3rd|\d+%)\b/u',
            ' ',
            $name,
        ) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    private static function dateDistanceDays(BankStatementTransaction $transaction, Invoice $invoice): ?int
    {
        if ($transaction->booking_date === null || $invoice->invoice_date === null) {
            return null;
        }

        return (int) abs($transaction->booking_date->diffInDays($invoice->invoice_date));
    }
}
