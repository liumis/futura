<?php

namespace App\Models;

use App\Enums\BankStatementDirection;
use App\Services\BankStatements\BankStatementCounterpartResolver;
use App\Services\BankStatements\BankStatementDirectionResolver;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

class BankStatementTransaction extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'bank_statement_import_id',
        'fingerprint',
        'month_key',
        'booking_date',
        'value_date',
        'direction',
        'amount',
        'currency',
        'account_iban',
        'bank_name',
        'counterpart_name',
        'counterpart_iban',
        'counterpart_bic',
        'description',
        'comment',
        'end_to_end_id',
        'account_servicer_ref',
        'transaction_id',
        'invoice_id',
        'payment_id',
        'employee_monthly_payment_id',
        'document_id',
        'business_trip_request_id',
        'no_document_needed',
        'is_historical',
        'is_refund',
        'overpayment_refunded',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'value_date' => 'date',
            'direction' => BankStatementDirection::class,
            'amount' => 'decimal:2',
            'no_document_needed' => 'boolean',
            'is_historical' => 'boolean',
            'is_refund' => 'boolean',
            'overpayment_refunded' => 'boolean',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'bank_statement_transaction_invoice')
            ->withPivot('amount')
            ->withTimestamps();
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function linkedInvoices()
    {
        if (! $this->relationLoaded('invoices')) {
            $this->load('invoices');
        }

        if ($this->invoices->isNotEmpty()) {
            return $this->invoices;
        }

        if ($this->invoice) {
            return collect([$this->invoice]);
        }

        return collect();
    }

    public function categoryInvoice(): ?Invoice
    {
        $linked = $this->linkedInvoices();
        if ($linked->isNotEmpty()) {
            if ($this->invoice_id) {
                $primary = $linked->first(
                    fn (Invoice $invoice): bool => (int) $invoice->getKey() === (int) $this->invoice_id,
                );
                if ($primary instanceof Invoice) {
                    return $primary;
                }
            }

            return $linked->first();
        }

        if ($this->relationLoaded('suggestedInvoice')) {
            $suggested = $this->getRelation('suggestedInvoice');

            return $suggested instanceof Invoice ? $suggested : null;
        }

        return null;
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function employeeMonthlyPayment(): BelongsTo
    {
        return $this->belongsTo(EmployeeMonthlyPayment::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function businessTripRequest(): BelongsTo
    {
        return $this->belongsTo(BusinessTripRequest::class);
    }

    public function isLinked(): bool
    {
        return $this->invoice_id !== null
            || $this->payment_id !== null
            || $this->employee_monthly_payment_id !== null
            || $this->document_id !== null
            || $this->business_trip_request_id !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithoutLinkedDocument(Builder $query): Builder
    {
        return $query
            ->whereNull('invoice_id')
            ->whereNull('payment_id')
            ->whereNull('employee_monthly_payment_id')
            ->whereNull('document_id')
            ->whereNull('business_trip_request_id')
            ->whereDoesntHave('invoices');
    }

    public function isLinkedToStatement(): bool
    {
        return $this->doesNotNeedDocument() && (bool) $this->import?->hasSharepointLink();
    }

    /**
     * Internal account move, bank fee/service charge, historical, or manually marked —
     * the SharePoint bank statement is the supporting document.
     */
    public function doesNotNeedDocument(): bool
    {
        return (bool) $this->is_historical
            || (bool) $this->no_document_needed
            || $this->isInternalTransfer()
            || $this->isBankServiceFee();
    }

    public function isHistorical(): bool
    {
        return (bool) $this->is_historical;
    }

    public function isResolved(): bool
    {
        return $this->isLinked() || $this->doesNotNeedDocument();
    }

    public function isInternalTransfer(): bool
    {
        return BankStatementDirectionResolver::isOwnTransfer(
            $this->account_iban,
            $this->counterpart_iban,
            $this->counterpart_name,
            $this->description,
            $this->end_to_end_id,
            $this->comment,
        );
    }

    public function isBankServiceFee(): bool
    {
        if ($this->isInternalTransfer()) {
            return false;
        }

        return BankStatementDirectionResolver::isBankServiceFee(
            $this->counterpart_name,
            $this->description,
            $this->end_to_end_id,
            $this->comment,
        );
    }

    /**
     * Customer/supplier refund remittance (not own-account bookkeeping transfers).
     */
    public function isRefund(): bool
    {
        if ((bool) $this->is_refund) {
            return true;
        }

        if ($this->isInternalTransfer()) {
            return false;
        }

        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            $this->counterpart_name,
            $this->description,
            $this->comment,
        ], fn (mixed $value): bool => filled($value)))));

        if ($haystack === '') {
            return false;
        }

        return str_contains($haystack, 'refund')
            || str_contains($haystack, 'grąžinim')
            || str_contains($haystack, 'grazinim')
            || str_contains($haystack, 'permoka');
    }

    public function statementDocumentLabel(): string
    {
        if ((bool) $this->is_refund) {
            return 'Refunded';
        }

        if ($this->isHistorical()) {
            return 'Historical';
        }

        if ($this->isInternalTransfer()) {
            return 'Own accounts';
        }

        if ($this->isBankServiceFee()) {
            return 'Bank fee';
        }

        return 'No document';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnTransfer(Builder $query): Builder
    {
        static::applyOwnTransferConstraint($query);

        return $query;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotOwnTransfer(Builder $query): Builder
    {
        return $query->whereNotIn(
            $query->getModel()->getQualifiedKeyName(),
            static::query()->select('id')->tap(function (Builder $sub): void {
                static::applyOwnTransferConstraint($sub);
            }),
        );
    }

    /**
     * @param  Builder<self>  $query
     */
    public static function applyOwnTransferConstraint(Builder $query): void
    {
        $ownIbans = array_keys(CompanyBankAccount::ownAccountIdentifierMap());

        $query->where(function (Builder $builder) use ($ownIbans): void {
            if ($ownIbans !== []) {
                $builder->where(function (Builder $ibanQuery) use ($ownIbans): void {
                    $ibanQuery
                        ->whereIn('counterpart_iban', $ownIbans)
                        ->where(function (Builder $notSame): void {
                            $notSame
                                ->whereNull('account_iban')
                                ->orWhereColumn('counterpart_iban', '!=', 'account_iban');
                        });
                });
            }

            foreach (BankStatementDirectionResolver::ownTransferLikePatterns() as $pattern) {
                $builder
                    ->orWhere('description', 'like', $pattern)
                    ->orWhere('counterpart_name', 'like', $pattern)
                    ->orWhere('comment', 'like', $pattern)
                    ->orWhere('end_to_end_id', 'like', $pattern);
            }

            foreach (BankStatementDirectionResolver::genericOwnTransferLabels() as $label) {
                $builder
                    ->orWhereRaw('LOWER(TRIM(counterpart_name)) = ?', [$label])
                    ->orWhereRaw('LOWER(TRIM(description)) = ?', [$label])
                    ->orWhere('counterpart_name', 'like', '% · '.$label)
                    ->orWhere('description', 'like', '% · '.$label);
            }
        });
    }

    /**
     * @return array{
     *     status: 'paid'|'partly_paid'|'unpaid'|'overpaid'|'underpaid'|'refund'|'refunded'|'settled',
     *     label: string,
     *     paid_amount: float,
     *     left_amount: float,
     *     overpayment_amount: float,
     *     detail: ?string
     * }
     */
    public function settlementSummary(): array
    {
        $amount = round(abs((float) $this->amount), 2);
        $isOutgoing = $this->direction !== null && ! $this->direction->isIncome();

        if ($this->isRefund() && ! $this->isLinked()) {
            $manual = (bool) $this->is_refund;

            return [
                'status' => ($manual || ! $isOutgoing) ? 'refunded' : 'refund',
                'label' => ($manual || ! $isOutgoing) ? 'Refunded' : 'Refund',
                'paid_amount' => $amount,
                'left_amount' => 0.0,
                'overpayment_amount' => 0.0,
                'detail' => $manual ? 'No document needed' : null,
            ];
        }

        if ($this->doesNotNeedDocument() && ! $this->isLinked()) {
            return [
                'status' => 'paid',
                'label' => $this->statementDocumentLabel(),
                'paid_amount' => $amount,
                'left_amount' => 0.0,
                'overpayment_amount' => 0.0,
                'detail' => $this->isHistorical()
                    ? 'Pre–go-live data (no linking)'
                    : ($this->isBankServiceFee()
                        ? 'Documented by bank statement'
                        : 'No document needed'),
            ];
        }

        if ($this->isRefund()) {
            return [
                'status' => $isOutgoing ? 'refund' : 'refunded',
                'label' => $isOutgoing ? 'Refund' : 'Refunded',
                'paid_amount' => $amount,
                'left_amount' => 0.0,
                'overpayment_amount' => 0.0,
                'detail' => null,
            ];
        }

        if ($this->invoice_id || $this->linkedInvoices()->isNotEmpty()) {
            $linked = $this->linkedInvoices();
            $invoiceFaceTotal = $this->invoiceLinkDocumentTotal($linked);
            $allocated = $this->invoiceLinkAllocatedAmount($linked);
            // Outgoing: compare pay vs full invoice document totals (catch short pays).
            // Incoming partial: compare vs allocated pivot (stay “partly paid”).
            // Incoming overpay: compare vs document totals (show overpayment).
            if ($isOutgoing || $amount > $invoiceFaceTotal + 0.02) {
                $compareTotal = $invoiceFaceTotal;
            } else {
                $compareTotal = $allocated;
            }
            $diff = round($amount - $compareTotal, 2);
            $currency = $this->currencyCode();
            $countDetail = $linked->count() > 1 ? $linked->count().' invoices' : null;

            if ($diff > 0.02) {
                $detailParts = [
                    'invoices '.Money::format($compareTotal, 2, $currency),
                    'pay '.Money::format($amount, 2, $currency),
                    'over '.Money::format($diff, 2, $currency),
                ];
                if ($countDetail !== null) {
                    $detailParts[] = $countDetail;
                }

                if ((bool) $this->overpayment_refunded) {
                    return [
                        'status' => 'settled',
                        'label' => 'Settled',
                        'paid_amount' => $amount,
                        'left_amount' => 0.0,
                        'overpayment_amount' => $diff,
                        'detail' => 'Overpayment refunded · '.implode(' · ', $detailParts),
                    ];
                }

                return [
                    'status' => 'overpaid',
                    'label' => 'Overpayment',
                    'paid_amount' => $amount,
                    'left_amount' => 0.0,
                    'overpayment_amount' => $diff,
                    'detail' => implode(' · ', $detailParts),
                ];
            }

            if ($diff < -0.02) {
                $short = abs($diff);
                $detailParts = [
                    'invoices '.Money::format($compareTotal, 2, $currency),
                    'pay '.Money::format($amount, 2, $currency),
                    'short '.Money::format($short, 2, $currency),
                ];
                if ($countDetail !== null) {
                    $detailParts[] = $countDetail;
                }

                return [
                    'status' => 'underpaid',
                    'label' => 'Sum mismatch',
                    'paid_amount' => $amount,
                    'left_amount' => $short,
                    'overpayment_amount' => 0.0,
                    'detail' => implode(' · ', $detailParts),
                ];
            }

            // Outgoing bank lines already left the account — status is Paid when totals match.
            if ($isOutgoing) {
                return [
                    'status' => 'paid',
                    'label' => 'Paid',
                    'paid_amount' => $amount,
                    'left_amount' => 0.0,
                    'overpayment_amount' => 0.0,
                    'detail' => $countDetail,
                ];
            }

            if ($linked->count() > 1) {
                $allPaid = $linked->every(fn (Invoice $invoice): bool => $invoice->isFullyPaid());
                $anyPartial = $linked->contains(fn (Invoice $invoice): bool => $invoice->isPartlyPaid());

                return [
                    'status' => $allPaid ? 'paid' : ($anyPartial ? 'partly_paid' : 'unpaid'),
                    'label' => $allPaid ? 'Paid' : ($anyPartial ? 'Partly paid' : 'Not paid'),
                    'paid_amount' => round($linked->sum(fn (Invoice $invoice): float => (float) ($invoice->paid_amount ?? 0)), 2),
                    'left_amount' => round($linked->sum(fn (Invoice $invoice): float => $invoice->outstandingAmount()), 2),
                    'overpayment_amount' => 0.0,
                    'detail' => $countDetail,
                ];
            }

            $invoice = $linked->first() ?? $this->invoice;
            $status = $invoice->paymentSettlementStatus();
            $paidAmount = round((float) ($invoice->paid_amount ?? 0), 2);
            $leftAmount = $invoice->outstandingAmount();

            return [
                'status' => $status,
                'label' => $invoice->paymentSettlementLabel(),
                'paid_amount' => $paidAmount,
                'left_amount' => $leftAmount,
                'overpayment_amount' => 0.0,
                'detail' => $status === 'partly_paid'
                    ? 'paid '.Money::format($paidAmount).' · left '.Money::format($leftAmount)
                    : null,
            ];
        }

        if ($this->businessTripRequest) {
            $trip = $this->businessTripRequest;
            $trip->loadMissing(['employee', 'bankStatementTransactions']);
            $summary = $trip->settlementSummary();

            return [
                'status' => $summary['status'],
                'label' => $summary['label'],
                'paid_amount' => $summary['paid_amount'],
                'left_amount' => $summary['left_amount'],
                'overpayment_amount' => 0.0,
                'detail' => implode(' · ', array_filter([
                    'trip '.Money::format($summary['expected_total']),
                    $summary['detail'],
                ])),
            ];
        }

        // Outgoing bank lines already left the account — status is always Paid.
        if ($isOutgoing) {
            return [
                'status' => 'paid',
                'label' => 'Paid',
                'paid_amount' => $amount,
                'left_amount' => 0.0,
                'overpayment_amount' => 0.0,
                'detail' => null,
            ];
        }

        if ($this->payment) {
            $paid = (bool) $this->payment->paid;

            return [
                'status' => $paid ? 'paid' : 'unpaid',
                'label' => $paid ? 'Paid' : 'Not paid',
                'paid_amount' => $paid ? round((float) $this->payment->amount, 2) : 0.0,
                'left_amount' => $paid ? 0.0 : round((float) $this->payment->amount, 2),
                'overpayment_amount' => 0.0,
                'detail' => null,
            ];
        }

        if ($this->employeeMonthlyPayment) {
            return [
                'status' => 'paid',
                'label' => 'Payslip',
                'paid_amount' => $amount,
                'left_amount' => 0.0,
                'overpayment_amount' => 0.0,
                'detail' => $this->employeeMonthlyPayment->employee?->fullName(),
            ];
        }

        if ($this->document) {
            return [
                'status' => 'paid',
                'label' => 'Document',
                'paid_amount' => $amount,
                'left_amount' => 0.0,
                'overpayment_amount' => 0.0,
                'detail' => $this->document->name,
            ];
        }

        // Cleared income without a linked document.
        return [
            'status' => 'paid',
            'label' => 'Paid',
            'paid_amount' => $amount,
            'left_amount' => 0.0,
            'overpayment_amount' => 0.0,
            'detail' => null,
        ];
    }

    /**
     * Sum of linked invoice document totals in bank currency (ignores pivot splits).
     *
     * @param  Collection<int, Invoice>  $linked
     */
    public function invoiceLinkDocumentTotal(Collection $linked): float
    {
        if ($linked->isEmpty()) {
            return 0.0;
        }

        $bankCurrency = Currency::normalize($this->currency ?? 'EUR');
        $total = 0.0;

        foreach ($linked as $invoice) {
            if (! $invoice instanceof Invoice) {
                continue;
            }

            $total += $invoice->comparableAmountForBank($bankCurrency);
        }

        return round($total, 2);
    }

    /**
     * Bank amount above what is covered by linked invoice totals / pivot allocations.
     *
     * @param  Collection<int, Invoice>  $linked
     */
    public function invoiceLinkOverpaymentAmount(Collection $linked, ?float $bankAmount = null): float
    {
        $bankAmount = round(abs($bankAmount ?? (float) $this->amount), 2);

        return max(0.0, round($bankAmount - $this->invoiceLinkAllocatedAmount($linked, $bankAmount), 2));
    }

    /**
     * Linked invoice coverage in bank currency (pivot amounts when set, else invoice totals).
     *
     * @param  Collection<int, Invoice>  $linked
     */
    public function invoiceLinkAllocatedAmount(Collection $linked, ?float $bankAmount = null): float
    {
        if ($linked->isEmpty()) {
            return 0.0;
        }

        $bankCurrency = Currency::normalize($this->currency ?? 'EUR');
        $allocated = 0.0;

        foreach ($linked as $invoice) {
            if (! $invoice instanceof Invoice) {
                continue;
            }

            $pivotAmount = round((float) ($invoice->pivot->amount ?? 0), 2);
            if ($pivotAmount >= 0.005) {
                $allocated += $this->invoiceCurrencyAmountAsBank($invoice, $pivotAmount, $bankCurrency);
            } else {
                $allocated += $invoice->comparableAmountForBank($bankCurrency);
            }
        }

        return round($allocated, 2);
    }

    private function invoiceCurrencyAmountAsBank(Invoice $invoice, float $amountInInvoiceCurrency, string $bankCurrency): float
    {
        $invoiceCurrency = $invoice->currencyCode();
        $bankCurrency = Currency::normalize($bankCurrency);
        $amountInInvoiceCurrency = round($amountInInvoiceCurrency, 2);

        if ($invoiceCurrency === $bankCurrency) {
            return $amountInInvoiceCurrency;
        }

        if ($bankCurrency === 'EUR') {
            $total = round((float) ($invoice->sum_inc_vat ?? 0), 2);
            if ($total >= 0.005 && $invoice->sum_inc_vat_eur !== null) {
                return round($amountInInvoiceCurrency / $total * (float) $invoice->sum_inc_vat_eur, 2);
            }

            return Currency::toEur(
                $amountInInvoiceCurrency,
                $invoiceCurrency,
                $invoice->currency_rate !== null ? (float) $invoice->currency_rate : null,
            ) ?? 0.0;
        }

        if ($invoiceCurrency === 'EUR') {
            return Currency::fromEur($amountInInvoiceCurrency, $bankCurrency) ?? 0.0;
        }

        $eur = Currency::toEur(
            $amountInInvoiceCurrency,
            $invoiceCurrency,
            $invoice->currency_rate !== null ? (float) $invoice->currency_rate : null,
        );

        return $eur === null ? 0.0 : (Currency::fromEur($eur, $bankCurrency) ?? 0.0);
    }

    public function currencyCode(): string
    {
        return Currency::normalize($this->currency ?? 'EUR');
    }

    /**
     * Source of funds: counterpart for income, company account for outcome.
     *
     * @return array{name: ?string, iban: ?string, is_counterpart: bool}
     */
    public function fromParty(): array
    {
        return $this->direction?->isIncome()
            ? $this->counterpartParty()
            : $this->ownAccountParty();
    }

    /**
     * Destination of funds: company account for income, counterpart for outcome.
     *
     * @return array{name: ?string, iban: ?string, is_counterpart: bool}
     */
    public function toParty(): array
    {
        return $this->direction?->isIncome()
            ? $this->ownAccountParty()
            : $this->counterpartParty();
    }

    public function partyLine(array $party): string
    {
        $parts = array_values(array_filter([
            $party['name'] ?? null,
            $party['iban'] ?? null,
        ], fn (mixed $value): bool => filled($value)));

        return $parts !== [] ? implode(' · ', $parts) : '—';
    }

    /**
     * @return array{name: ?string, iban: ?string, is_counterpart: bool}
     */
    public function ownAccountParty(): array
    {
        $account = CompanyBankAccount::resolveFromStatementAccount($this->account_iban);
        $iban = $account?->iban ?? $this->account_iban;
        $name = implode(' · ', array_filter([
            $account?->bank_name ?: $this->bank_name,
            $account?->type?->label(),
        ], fn (mixed $value): bool => filled($value)));

        return [
            'name' => $name !== '' ? $name : null,
            'iban' => filled($iban) ? (string) $iban : null,
            'is_counterpart' => false,
        ];
    }

    /**
     * @return array{name: ?string, iban: ?string, is_counterpart: bool}
     */
    public function counterpartParty(): array
    {
        $account = CompanyBankAccount::resolveFromStatementAccount($this->counterpart_iban)
            ?? CompanyBankAccount::resolveFromPartyName($this->counterpart_name);
        $iban = $account?->iban ?? $this->counterpart_iban;
        $rawName = filled($this->counterpart_name) ? (string) $this->counterpart_name : null;
        $resolved = BankStatementCounterpartResolver::resolve(
            $rawName,
            $this->description,
            $this->end_to_end_id,
            $this,
        );
        $rawName = $resolved ?? $rawName;
        $generic = BankStatementDirectionResolver::isGenericOwnTransferLabel($rawName);
        $accountLabel = $account === null ? null : implode(' · ', array_filter([
            $account->bank_name ?: CompanyBankAccount::bankNameFromIban((string) ($account->iban ?? '')),
            $account->type?->label(),
        ], fn (mixed $value): bool => filled($value)));

        $name = $rawName;
        if ($accountLabel !== null && $accountLabel !== '') {
            // Prefer bank · type for own-account counterparts (Income ↔ Credit card moves).
            if ($account !== null || $generic || $rawName === null) {
                $name = $accountLabel;
            }
        }

        return [
            'name' => $name,
            'iban' => filled($iban) ? (string) $iban : null,
            'is_counterpart' => $account === null,
        ];
    }

    public function incomeAmount(): float
    {
        if ($this->isInternalTransfer()) {
            return 0.0;
        }

        return $this->direction === BankStatementDirection::Credit ? (float) $this->amount : 0.0;
    }

    public function outcomeAmount(): float
    {
        if ($this->isInternalTransfer()) {
            return 0.0;
        }

        return $this->direction === BankStatementDirection::Debit ? (float) $this->amount : 0.0;
    }

    public function displayComment(): ?string
    {
        if (filled($this->comment)) {
            return (string) $this->comment;
        }

        $importComment = $this->import?->comment;

        return filled($importComment) ? (string) $importComment : null;
    }
}
