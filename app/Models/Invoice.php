<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class Invoice extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'order_id',
        'invoice_series_id',
        'series_number',
        'invoice_number',
        'invoice_date',
        'sum_without_vat',
        'vat',
        'sum_inc_vat',
        'currency',
        'currency_rate',
        'sum_inc_vat_eur',
        'payment_term',
        'paid_amount',
        'paid',
        'private_provider',
        'comment',
        'is_history',
        'income_type_id',
        'expense_type_id',
        'is_own_account',
        'gl_generated_type',
        'gl_generated_key',
        'vat_rate_id',
        'upload_date',
        'uploaded_by',
        'pdf_path',
        'file_content',
        'file_name',
        'file_mime',
        'sharepoint_web_url',
        'sharepoint_item_id',
        'sharepoint_path',
        'sharepoint_files',
        'additional_documents',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'sum_without_vat' => 'decimal:2',
            'vat' => 'decimal:2',
            'sum_inc_vat' => 'decimal:2',
            'currency_rate' => 'decimal:6',
            'sum_inc_vat_eur' => 'decimal:2',
            'payment_term' => 'integer',
            'paid_amount' => 'decimal:2',
            'paid' => 'boolean',
            'private_provider' => 'boolean',
            'is_own_account' => 'boolean',
            'is_history' => 'boolean',
            'upload_date' => 'date',
            'additional_documents' => 'array',
            'sharepoint_files' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function currencyCode(): string
    {
        return Currency::normalize($this->currency);
    }

    public function isEuroCurrency(): bool
    {
        return $this->currencyCode() === 'EUR';
    }

    /**
     * Snapshot FX conversion from the currencies list (foreign units per 1 EUR).
     */
    public function syncEuroConversion(bool $refreshRate = true): void
    {
        $code = $this->currencyCode();
        $original = round((float) ($this->sum_inc_vat ?? 0), 2);

        if ($code === 'EUR') {
            $this->currency_rate = 1;
            $this->sum_inc_vat_eur = $original;

            return;
        }

        $rate = $refreshRate || $this->currency_rate === null || (float) $this->currency_rate <= 0
            ? Currency::rateFor($code)
            : (float) $this->currency_rate;

        if ($rate === null || $rate <= 0) {
            return;
        }

        $this->currency_rate = $rate;
        $this->sum_inc_vat_eur = Currency::toEur($original, $code, $rate);
    }

    public function amountInEur(): float
    {
        if ($this->isEuroCurrency()) {
            return round((float) ($this->sum_inc_vat ?? 0), 2);
        }

        if ($this->sum_inc_vat_eur !== null) {
            return round((float) $this->sum_inc_vat_eur, 2);
        }

        return Currency::toEur((float) ($this->sum_inc_vat ?? 0), $this->currencyCode()) ?? 0.0;
    }

    public function outstandingAmountInEur(): float
    {
        if ($this->isEuroCurrency()) {
            return $this->outstandingAmount();
        }

        $total = round((float) ($this->sum_inc_vat ?? 0), 2);
        if (abs($total) < 0.005) {
            return 0.0;
        }

        $ratio = $this->outstandingAmount() / abs($total);

        return round(abs($this->amountInEur()) * $ratio, 2);
    }

    /**
     * Amount in the invoice currency that corresponds to a bank payment.
     */
    public function amountFromBankPayment(float $bankAmount, ?string $bankCurrency = 'EUR'): float
    {
        $bankAmount = round($bankAmount, 2);
        $bankCurrency = Currency::normalize($bankCurrency);
        $invoiceCurrency = $this->currencyCode();

        if ($bankCurrency === $invoiceCurrency) {
            return $bankAmount;
        }

        // Prefer settling the remaining original balance when the bank amount matches EUR total/outstanding.
        if ($bankCurrency === 'EUR') {
            if (abs($bankAmount - $this->outstandingAmountInEur()) < 0.02
                || abs($bankAmount - abs($this->amountInEur())) < 0.02) {
                return $this->outstandingAmount();
            }

            $converted = Currency::fromEur(
                $bankAmount,
                $invoiceCurrency,
                $this->currency_rate !== null ? (float) $this->currency_rate : null,
            );

            return $converted ?? 0.0;
        }

        $eur = Currency::toEur($bankAmount, $bankCurrency);
        if ($eur === null) {
            return 0.0;
        }

        if ($invoiceCurrency === 'EUR') {
            return $eur;
        }

        return Currency::fromEur(
            $eur,
            $invoiceCurrency,
            $this->currency_rate !== null ? (float) $this->currency_rate : null,
        ) ?? 0.0;
    }

    public function comparableAmountForBank(?string $bankCurrency = 'EUR'): float
    {
        return Currency::normalize($bankCurrency) === 'EUR'
            ? $this->amountInEur()
            : round((float) ($this->sum_inc_vat ?? 0), 2);
    }

    public function comparableOutstandingForBank(?string $bankCurrency = 'EUR'): float
    {
        return Currency::normalize($bankCurrency) === 'EUR'
            ? $this->outstandingAmountInEur()
            : $this->outstandingAmount();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Display name for the Counterpart column.
     * Income/sales invoices must show the customer — never our own company.
     */
    public function counterpartLabel(): string
    {
        $this->loadMissing(['contact', 'order.user', 'bankStatementTransactions', 'bankStatementTransactionLinks']);

        if ($this->shouldShowSalesCustomer()) {
            $customer = $this->resolvedSalesCustomerName();
            if ($customer !== null) {
                return $customer;
            }

            // Own company as contact with no customer resolved — do not show Futura.
            if ($this->contactIsOwnCompany()) {
                return '—';
            }
        }

        return trim((string) ($this->contact?->company_name ?? '')) ?: '—';
    }

    /**
     * Income (or FTE sales) rows should prefer the customer over the issuer contact.
     */
    public function shouldShowSalesCustomer(): bool
    {
        if ($this->is_own_account) {
            return false;
        }

        if ($this->income_type_id && ! $this->expense_type_id) {
            return true;
        }

        return $this->looksLikeSalesInvoice();
    }

    public function resolvedSalesCustomerName(): ?string
    {
        $this->loadMissing(['order.user', 'bankStatementTransactions', 'bankStatementTransactionLinks']);

        $customer = $this->order?->user;
        if ($customer !== null) {
            $name = trim((string) ($customer->company_name ?: $customer->name));
            if ($name !== '' && ! $this->nameLooksLikeOwnCompany($name)) {
                return $name;
            }
        }

        if ($this->contact !== null && ! $this->contactIsOwnCompany()) {
            $name = trim((string) ($this->contact->company_name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        foreach ($this->bankPaymentCounterparts() as $name) {
            if ($name !== '' && ! $this->nameLooksLikeOwnCompany($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function bankPaymentCounterparts(): array
    {
        $this->loadMissing(['bankStatementTransactions', 'bankStatementTransactionLinks']);

        return $this->bankStatementTransactions
            ->merge($this->bankStatementTransactionLinks)
            ->sortByDesc(fn (BankStatementTransaction $tx): string => (string) ($tx->booking_date?->toDateString() ?? ''))
            ->map(function (BankStatementTransaction $tx): string {
                $name = trim((string) ($tx->counterpart_name ?? ''));
                if ($name === '') {
                    return '';
                }

                $upper = mb_strtoupper($name);
                if (str_starts_with($upper, 'DU ')
                    || str_contains($upper, 'VALSTYBIN')
                    || str_contains($upper, 'VMI')
                    || str_contains($upper, 'SODRA')
                    || str_contains($upper, 'MUITIN')) {
                    return '';
                }

                return $name;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function contactIsOwnCompany(): bool
    {
        if (! $this->contact_id) {
            return false;
        }

        static $issuerId = null;
        if ($issuerId === null) {
            try {
                $issuerId = (int) CompanySetting::instance()->syncContact()->getKey();
            } catch (\Throwable) {
                $issuerId = 0;
            }
        }

        if ($issuerId > 0 && (int) $this->contact_id === $issuerId) {
            return true;
        }

        return $this->nameLooksLikeOwnCompany((string) ($this->contact?->company_name ?? ''));
    }

    private function nameLooksLikeOwnCompany(string $name): bool
    {
        $name = mb_strtolower(trim($name));

        return $name !== '' && str_contains($name, 'futura textiles');
    }

    /**
     * Point sales invoices at the customer contact (not our own company).
     */
    public function syncSalesCounterpartFromOrder(): bool
    {
        return $this->syncSalesCounterpart();
    }

    /**
     * Point income/sales invoices at the customer contact (order user or bank payer).
     */
    public function syncSalesCounterpart(): bool
    {
        if (! $this->shouldShowSalesCustomer() || ! $this->contactIsOwnCompany()) {
            return false;
        }

        $this->loadMissing(['order.user', 'bankStatementTransactions', 'bankStatementTransactionLinks']);

        $buyer = null;
        $customer = $this->order?->user;
        if ($customer !== null) {
            $buyer = Contact::syncFromCustomer($customer);
        }

        if ($buyer === null) {
            $name = $this->bankPaymentCounterparts()[0] ?? null;
            if (filled($name)) {
                $buyer = Contact::syncFromCustomerDetails($name);
            }
        }

        if ($buyer === null || (int) $this->contact_id === (int) $buyer->getKey()) {
            return false;
        }

        $this->forceFill(['contact_id' => $buyer->getKey()])->save();

        return true;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoiceSeries(): BelongsTo
    {
        return $this->belongsTo(InvoiceSeries::class);
    }

    public function uploadedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function incomeType(): BelongsTo
    {
        return $this->belongsTo(IncomeType::class);
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class);
    }

    public const OWN_ACCOUNT_FILTER = 'own_account';

    public const OWN_ACCOUNT_LABEL = 'Savo sąskaita';

    /** Supplier credit note — list amounts use + (not −). */
    public const CREDIT_NOTE_EXPENSE_TYPE = 'Kreditinė sąskaita';

    /** Default days until payment is due when none is set on the invoice or customer. */
    public const DEFAULT_PAYMENT_TERM_DAYS = 30;

    /**
     * @return 'income'|'expense'|'own_account'|null
     */
    public function financeCategoryKind(): ?string
    {
        if ($this->is_own_account) {
            return self::OWN_ACCOUNT_FILTER;
        }

        if ($this->income_type_id) {
            return 'income';
        }

        if ($this->expense_type_id) {
            return 'expense';
        }

        return null;
    }

    /**
     * Sign used for invoice list amounts (+ income / − expense).
     * Supplier credit notes (Kreditinė sąskaita) display as + even though they
     * stay under Expense categories for filtering.
     * Missing type defaults to expense (company-account outcome) unless bank
     * links clearly show incoming money.
     *
     * @return 'income'|'expense'|null
     */
    public function displayAmountKind(): ?string
    {
        if ($this->isSupplierCreditNote()) {
            return 'income';
        }

        $kind = $this->financeCategoryKind();

        if ($kind === 'income' || $kind === 'expense') {
            return $kind;
        }

        if ($kind === self::OWN_ACCOUNT_FILTER) {
            return null;
        }

        $fromBank = $this->inferredAmountKindFromBankLinks();
        if ($fromBank !== null) {
            return $fromBank;
        }

        // Uncategorised invoices are treated as outcomes from company accounts.
        return 'expense';
    }

    public function isSupplierCreditNote(): bool
    {
        $this->loadMissing('expenseType');

        return $this->expense_type_id !== null
            && $this->expenseType?->name === self::CREDIT_NOTE_EXPENSE_TYPE;
    }

    public static function creditNoteExpenseTypeId(): ?int
    {
        $id = ExpenseType::query()->where('name', self::CREDIT_NOTE_EXPENSE_TYPE)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return 'income'|'expense'|null
     */
    public function inferredAmountKindFromBankLinks(): ?string
    {
        $links = $this->linkedBankStatementTransactions()
            ->reject(fn (BankStatementTransaction $row): bool => $row->isInternalTransfer());

        if ($links->isEmpty()) {
            return null;
        }

        $hasIncome = $links->contains(
            fn (BankStatementTransaction $row): bool => (bool) $row->direction?->isIncome(),
        );
        $hasOutcome = $links->contains(
            fn (BankStatementTransaction $row): bool => $row->direction !== null && ! $row->direction->isIncome(),
        );

        if ($hasOutcome && ! $hasIncome) {
            return 'expense';
        }

        if ($hasIncome && ! $hasOutcome) {
            return 'income';
        }

        return null;
    }

    public function financeCategoryName(): ?string
    {
        $this->loadMissing(['incomeType', 'expenseType']);

        return match ($this->financeCategoryKind()) {
            'income' => $this->incomeType?->name,
            'expense' => $this->expenseType?->name,
            self::OWN_ACCOUNT_FILTER => self::OWN_ACCOUNT_LABEL,
            default => null,
        };
    }

    public static function goodsIncomeTypeId(): ?int
    {
        $id = IncomeType::query()->where('name', 'Prekės')->value('id');

        return $id !== null ? (int) $id : null;
    }

    public static function goodsExpenseTypeId(): ?int
    {
        $id = ExpenseType::query()->where('name', 'Prekės')->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function looksLikeSalesInvoice(): bool
    {
        if ($this->order_id) {
            return true;
        }

        $number = strtoupper(trim((string) ($this->invoice_number ?? '')));

        return $number !== '' && str_starts_with($number, 'FTE');
    }

    public function looksLikeOwnAccountTransfer(): bool
    {
        $hay = mb_strtolower(trim((string) ($this->invoice_number ?? '')));

        if ($hay === '') {
            return false;
        }

        return str_contains($hay, 'tarp savo')
            || str_contains($hay, 'between account')
            || str_contains($hay, 'savo sąskaita')
            || str_contains($hay, 'savo saskaita');
    }

    /**
     * Prefill missing category from provider default, then known sales / own-account heuristics.
     */
    public function applySuggestedCategoryIfMissing(): bool
    {
        if ($this->is_own_account || $this->income_type_id || $this->expense_type_id) {
            return false;
        }

        if ($this->looksLikeOwnAccountTransfer()) {
            $this->forceFill([
                'is_own_account' => true,
                'income_type_id' => null,
                'expense_type_id' => null,
            ])->save();

            return true;
        }

        $this->loadMissing('contact');
        $providerDefault = $this->contact?->defaultFinanceTypeValue();
        if ($providerDefault !== null) {
            $parsed = self::parseFinanceTypeFilterValue($providerDefault);
            $this->forceFill($parsed)->save();

            return true;
        }

        if (! $this->looksLikeSalesInvoice()) {
            return false;
        }

        $incomeTypeId = self::goodsIncomeTypeId();
        if ($incomeTypeId === null) {
            return false;
        }

        $this->forceFill([
            'income_type_id' => $incomeTypeId,
            'expense_type_id' => null,
            'is_own_account' => false,
        ])->save();

        return true;
    }

    /**
     * Stored category only (no sales / own-account suggestions).
     *
     * @return 'income:123'|'expense:123'|'own_account'|null
     */
    public function storedFinanceTypeValue(): ?string
    {
        if ($this->is_own_account) {
            return self::OWN_ACCOUNT_FILTER;
        }

        if ($this->income_type_id) {
            return 'income:'.$this->income_type_id;
        }

        if ($this->expense_type_id) {
            return 'expense:'.$this->expense_type_id;
        }

        return null;
    }

    /**
     * @return 'income:123'|'expense:123'|'own_account'|null
     */
    public function financeTypeFilterValue(): ?string
    {
        $stored = $this->storedFinanceTypeValue();
        if ($stored !== null) {
            return $stored;
        }

        if ($this->looksLikeOwnAccountTransfer()) {
            return self::OWN_ACCOUNT_FILTER;
        }

        if ($this->looksLikeSalesInvoice()) {
            $incomeTypeId = self::goodsIncomeTypeId();

            return $incomeTypeId !== null ? 'income:'.$incomeTypeId : null;
        }

        return null;
    }

    /**
     * @return array{income_type_id: int|null, expense_type_id: int|null, is_own_account: bool}
     */
    public static function parseFinanceTypeFilterValue(?string $value): array
    {
        if ($value === self::OWN_ACCOUNT_FILTER) {
            return [
                'income_type_id' => null,
                'expense_type_id' => null,
                'is_own_account' => true,
            ];
        }

        if (! filled($value) || ! is_string($value)) {
            return [
                'income_type_id' => null,
                'expense_type_id' => null,
                'is_own_account' => false,
            ];
        }

        if (str_starts_with($value, 'income:')) {
            return [
                'income_type_id' => (int) substr($value, strlen('income:')),
                'expense_type_id' => null,
                'is_own_account' => false,
            ];
        }

        if (str_starts_with($value, 'expense:')) {
            return [
                'income_type_id' => null,
                'expense_type_id' => (int) substr($value, strlen('expense:')),
                'is_own_account' => false,
            ];
        }

        return [
            'income_type_id' => null,
            'expense_type_id' => null,
            'is_own_account' => false,
        ];
    }

    public static function isValidFinanceCategoryValue(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if ($value === self::OWN_ACCOUNT_FILTER) {
            return true;
        }

        if (str_starts_with($value, 'income:')) {
            $id = (int) substr($value, strlen('income:'));

            return $id > 0 && IncomeType::query()->whereKey($id)->exists();
        }

        if (str_starts_with($value, 'expense:')) {
            $id = (int) substr($value, strlen('expense:'));

            return $id > 0 && ExpenseType::query()->whereKey($id)->exists();
        }

        return false;
    }

    public function applyFinanceCategory(?string $value): bool
    {
        $value = filled($value) ? trim((string) $value) : null;

        if (! self::isValidFinanceCategoryValue($value)) {
            return false;
        }

        $category = self::parseFinanceTypeFilterValue($value);

        $unchanged = (int) ($this->income_type_id ?? 0) === (int) ($category['income_type_id'] ?? 0)
            && (int) ($this->expense_type_id ?? 0) === (int) ($category['expense_type_id'] ?? 0)
            && (bool) $this->is_own_account === $category['is_own_account'];

        if (! $unchanged) {
            $this->forceFill($category)->save();
        }

        $this->loadMissing('contact');
        $this->contact?->rememberDefaultCategoryFromInvoice($this->fresh() ?? $this);

        return ! $unchanged;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function financeCategorySelectOptions(): array
    {
        return [
            'Income' => IncomeType::query()
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (IncomeType $type): array => ['income:'.$type->id => $type->name])
                ->all(),
            'Expense' => ExpenseType::query()
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (ExpenseType $type): array => ['expense:'.$type->id => $type->name])
                ->all(),
            'Other' => [
                self::OWN_ACCOUNT_FILTER => self::OWN_ACCOUNT_LABEL,
            ],
        ];
    }

    public static function financeCategoryLabel(?string $value): ?string
    {
        if (! filled($value) || ! is_string($value)) {
            return null;
        }

        foreach (self::financeCategorySelectOptions() as $group) {
            if (isset($group[$value])) {
                return $group[$value];
            }
        }

        return null;
    }

    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    public function financeLines(): HasMany
    {
        return $this->hasMany(InvoiceFinanceLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function bankStatementTransactions(): HasMany
    {
        return $this->hasMany(BankStatementTransaction::class);
    }

    public function bankStatementTransactionLinks(): BelongsToMany
    {
        return $this->belongsToMany(BankStatementTransaction::class, 'bank_statement_transaction_invoice')
            ->withPivot('amount')
            ->withTimestamps();
    }

    public function salesOffsetLinks(): HasMany
    {
        return $this->hasMany(InvoiceOffsetLink::class, 'sales_invoice_id');
    }

    public function purchaseOffsetLinks(): HasMany
    {
        return $this->hasMany(InvoiceOffsetLink::class, 'purchase_invoice_id');
    }

    public function hasOffsetLinks(): bool
    {
        if (! Schema::hasTable('invoice_offset_links')) {
            return false;
        }

        if ($this->relationLoaded('salesOffsetLinks') && $this->salesOffsetLinks->isNotEmpty()) {
            return true;
        }

        if ($this->relationLoaded('purchaseOffsetLinks') && $this->purchaseOffsetLinks->isNotEmpty()) {
            return true;
        }

        return $this->salesOffsetLinks()->exists() || $this->purchaseOffsetLinks()->exists();
    }

    /**
     * Offset / užskaita allocations where this invoice is the sales (debtor) side.
     */
    public function linkedOffsetAllocatedTotal(): float
    {
        if (! Schema::hasTable('invoice_offset_links')) {
            return 0.0;
        }

        if ($this->relationLoaded('salesOffsetLinks')) {
            return round((float) $this->salesOffsetLinks->sum('amount'), 2);
        }

        return round((float) $this->salesOffsetLinks()->sum('amount'), 2);
    }

    /**
     * Offset / užskaita allocations where this invoice is the purchase (creditor) side.
     */
    public function linkedPurchaseOffsetAllocatedTotal(): float
    {
        if (! Schema::hasTable('invoice_offset_links')) {
            return 0.0;
        }

        if ($this->relationLoaded('purchaseOffsetLinks')) {
            return round((float) $this->purchaseOffsetLinks->sum('amount'), 2);
        }

        return round((float) $this->purchaseOffsetLinks()->sum('amount'), 2);
    }

    /**
     * Bank statement lines linked via primary FK and/or pivot (same data as Bank Statements).
     *
     * @return \Illuminate\Support\Collection<int, BankStatementTransaction>
     */
    public function linkedBankStatementTransactions()
    {
        $this->loadMissing(['bankStatementTransactions', 'bankStatementTransactionLinks']);

        return $this->bankStatementTransactions
            ->merge($this->bankStatementTransactionLinks)
            ->unique(fn (BankStatementTransaction $row): int => (int) $row->getKey())
            ->sortByDesc(fn (BankStatementTransaction $row): string => optional($row->booking_date)->format('Y-m-d').'-'.$row->getKey())
            ->values();
    }

    public function hasSharepointLink(): bool
    {
        return filled($this->sharepoint_web_url)
            || filled($this->sharepoint_item_id)
            || $this->sharepointFiles() !== [];
    }

    public function hasStoredFile(): bool
    {
        return $this->hasSharepointLink();
    }

    public function languageSharepointWebUrl(string $lang): ?string
    {
        $file = $this->sharepointFileForLanguage($lang);

        return filled($file['web_url'] ?? null) ? (string) $file['web_url'] : null;
    }

    public function primarySharepointUrl(): ?string
    {
        if (filled($this->sharepoint_web_url)) {
            return (string) $this->sharepoint_web_url;
        }

        foreach (['lt', 'en'] as $lang) {
            $url = $this->languageSharepointWebUrl($lang);
            if ($url !== null) {
                return $url;
            }
        }

        foreach ($this->sharepointFiles() as $file) {
            if (filled($file['web_url'] ?? null)) {
                return (string) $file['web_url'];
            }
        }

        return null;
    }

    /**
     * SharePoint web URL for a language PDF only (no local route fallback).
     */
    public function languagePdfUrl(string $lang): ?string
    {
        return $this->languageSharepointWebUrl(strtolower(trim($lang)));
    }

    /**
     * Invoice number · LT / EN — SharePoint web URLs only.
     */
    public function openDocumentLinksHtml(
        string $linkClass = 'text-primary-600 hover:underline dark:text-primary-400',
    ): string {
        $number = e($this->displayNumber());
        $sharepointUrl = $this->primarySharepointUrl();
        $ltUrl = $this->languagePdfUrl('lt');
        $enUrl = $this->languagePdfUrl('en');

        $parts = [
            $sharepointUrl !== null
                ? '<a href="'.e($sharepointUrl).'" target="_blank" rel="noopener" class="'.$linkClass.'" title="Open on SharePoint">'.$number.'</a>'
                : ($ltUrl !== null
                    ? '<a href="'.e($ltUrl).'" target="_blank" rel="noopener" class="'.$linkClass.'" title="Open on SharePoint">'.$number.'</a>'
                    : $number),
        ];

        if ($ltUrl !== null && $enUrl !== null) {
            $parts[] = '<a href="'.e($ltUrl).'" target="_blank" rel="noopener" class="'.$linkClass.'">LT</a>'
                .' / '
                .'<a href="'.e($enUrl).'" target="_blank" rel="noopener" class="'.$linkClass.'">EN</a>';
        }

        return '<span class="whitespace-nowrap">'.implode(' · ', $parts).'</span>';
    }

    /**
     * @return list<array{lang?: string, name?: string, path?: string, item_id?: ?string, web_url?: ?string}>
     */
    public function sharepointFiles(): array
    {
        if (! is_array($this->sharepoint_files)) {
            return [];
        }

        return array_values(array_filter(
            $this->sharepoint_files,
            fn ($row): bool => is_array($row) && (filled($row['item_id'] ?? null) || filled($row['web_url'] ?? null)),
        ));
    }

    /**
     * @return array{lang?: string, name?: string, path?: string, item_id?: ?string, web_url?: ?string}|null
     */
    public function sharepointFileForLanguage(string $lang): ?array
    {
        $lang = strtolower(trim($lang));

        foreach ($this->sharepointFiles() as $file) {
            if (strtolower((string) ($file['lang'] ?? '')) === $lang) {
                return $file;
            }
        }

        return null;
    }

    public function hasBilingualSalesPdfs(): bool
    {
        return $this->sharepointFileForLanguage('lt') !== null
            && $this->sharepointFileForLanguage('en') !== null;
    }

    public function canOpenLanguagePdf(): bool
    {
        if ($this->is_own_account) {
            return false;
        }

        return $this->looksLikeSalesInvoice()
            || $this->hasBilingualSalesPdfs()
            || ($this->file_mime === 'application/pdf' && ($this->order_id || $this->income_type_id));
    }

    public function displayNumber(): string
    {
        return filled($this->invoice_number) ? (string) $this->invoice_number : 'Invoice #'.$this->getKey();
    }

    public static function findByNumber(?string $number): ?self
    {
        $number = trim((string) $number);
        if ($number === '') {
            return null;
        }

        return static::query()->where('invoice_number', $number)->first();
    }

    public function dueDate(): ?Carbon
    {
        if ($this->invoice_date === null || $this->payment_term === null) {
            return null;
        }

        return $this->invoice_date->copy()->addDays(max(0, (int) $this->payment_term));
    }

    public function outstandingAmount(): float
    {
        $total = round((float) $this->sum_inc_vat, 2);
        $paid = round((float) ($this->paid_amount ?? 0), 2);

        // Supplier credit notes keep a negative face value; outstanding is the unsettled magnitude.
        if ($total < 0) {
            return max(0, round(abs($total) - abs($paid), 2));
        }

        return max(0, round($total - $paid, 2));
    }

    public function isFullyPaid(): bool
    {
        return (bool) $this->paid || self::amountsAreFullyPaid($this->paid_amount, $this->sum_inc_vat);
    }

    public function isPartlyPaid(): bool
    {
        $paid = round((float) ($this->paid_amount ?? 0), 2);

        return $paid > 0.004 && ! $this->isFullyPaid();
    }

    /**
     * @return 'paid'|'partly_paid'|'unpaid'
     */
    public function paymentSettlementStatus(): string
    {
        if ($this->isFullyPaid()) {
            return 'paid';
        }

        if ($this->isPartlyPaid()) {
            return 'partly_paid';
        }

        return 'unpaid';
    }

    public function paymentSettlementLabel(): string
    {
        return match ($this->paymentSettlementStatus()) {
            'paid' => 'Paid',
            'partly_paid' => 'Partly paid',
            default => 'Not paid',
        };
    }

    public function markFullyPaid(): void
    {
        $total = round((float) ($this->sum_inc_vat ?? 0), 2);

        $this->forceFill([
            'paid_amount' => $total,
            'paid' => abs($total) >= 0.005,
        ]);
        $this->normalizePaymentState();
        $this->save();
    }

    public function markUnpaid(): void
    {
        $this->forceFill([
            'paid_amount' => 0,
            'paid' => false,
        ]);
        $this->normalizePaymentState();
        $this->save();
    }

    public function applyBankPaymentAmount(float $amount): void
    {
        $total = round((float) $this->sum_inc_vat, 2);
        $paid = round((float) ($this->paid_amount ?? 0), 2);
        $delta = max(0, round($amount, 2));

        if ($total < 0) {
            // Credit notes: move paid_amount from 0 toward the negative total.
            $next = round($paid - $delta, 2);
            $this->forceFill([
                'paid_amount' => max($total, min(0, $next)),
                'paid' => false,
            ]);
        } else {
            $this->forceFill([
                'paid_amount' => min($total, round($paid + $delta, 2)),
                'paid' => false,
            ]);
        }

        $this->normalizePaymentState();
        $this->save();
    }

    /**
     * After a bank link, snap to fully paid when the payment covers the invoice
     * (remaining balance, EUR-comparable amount, or face-value match).
     */
    public function markPaidIfCoveredByBankPayment(float $bankPaymentAmount, ?string $bankCurrency = 'EUR'): void
    {
        $this->refresh();

        $total = round((float) ($this->sum_inc_vat ?? 0), 2);
        if (abs($total) < 0.005) {
            return;
        }

        $bankPaymentAmount = round(abs($bankPaymentAmount), 2);
        $bankCurrency = Currency::normalize($bankCurrency ?? 'EUR');

        $covered = $this->outstandingAmount() < 0.005
            || $this->comparableOutstandingForBank($bankCurrency) < 0.02
            || abs($bankPaymentAmount - abs($this->comparableAmountForBank($bankCurrency))) < 0.02
            || abs($bankPaymentAmount - abs($total)) < 0.02;

        if (! $covered) {
            return;
        }

        $this->forceFill([
            'paid_amount' => $total,
            'paid' => true,
        ])->save();
    }

    public function reverseBankPaymentAmount(float $amount): void
    {
        $total = round((float) ($this->sum_inc_vat ?? 0), 2);
        $paid = round((float) ($this->paid_amount ?? 0), 2);
        $delta = max(0, round($amount, 2));

        if ($total < 0) {
            // Credit notes: move paid_amount back toward 0.
            $next = round($paid + $delta, 2);
            $this->forceFill([
                'paid_amount' => min(0, max($total, $next)),
                'paid' => false,
            ]);
        } else {
            $this->forceFill([
                'paid_amount' => max(0, round($paid - $delta, 2)),
                'paid' => false,
            ]);
        }

        $this->normalizePaymentState();
        $this->save();
    }

    public function hasBankPaymentLinks(): bool
    {
        if ($this->relationLoaded('bankStatementTransactionLinks')
            && $this->bankStatementTransactionLinks->isNotEmpty()) {
            return true;
        }

        if ($this->relationLoaded('bankStatementTransactions')
            && $this->bankStatementTransactions->isNotEmpty()) {
            return true;
        }

        return $this->bankStatementTransactionLinks()->exists()
            || $this->bankStatementTransactions()->exists();
    }

    /**
     * Sum of linked bank payment allocations in invoice currency (pivot amounts, else converted bank amount).
     */
    public function linkedBankPaymentAllocatedTotal(): float
    {
        $this->loadMissing(['bankStatementTransactionLinks', 'bankStatementTransactions']);

        $total = 0.0;
        $seen = [];

        foreach ($this->bankStatementTransactionLinks as $transaction) {
            $id = (int) $transaction->getKey();
            $seen[$id] = true;
            $pivotAmount = round((float) ($transaction->pivot->amount ?? 0), 2);
            if ($pivotAmount >= 0.005) {
                $total += $pivotAmount;
            } else {
                $total += $this->convertBankAmountToInvoiceCurrency(
                    abs((float) $transaction->amount),
                    $transaction->currency,
                );
            }
        }

        foreach ($this->bankStatementTransactions as $transaction) {
            $id = (int) $transaction->getKey();
            if (isset($seen[$id])) {
                continue;
            }

            $total += $this->convertBankAmountToInvoiceCurrency(
                abs((float) $transaction->amount),
                $transaction->currency,
            );
        }

        return round($total, 2);
    }

    /**
     * Recalculate paid / partly paid from linked bank payments + invoice offsets (does not persist).
     * Overpayment vs invoice total is reflected on the bank settlement side.
     */
    public function applyBankLinkPaymentState(): bool
    {
        $hasBank = $this->hasBankPaymentLinks();
        $hasOffset = $this->hasOffsetLinks();

        if (! $hasBank && ! $hasOffset) {
            return false;
        }

        $bankAllocated = $hasBank ? $this->linkedBankPaymentAllocatedTotal() : 0.0;
        $offsetAllocated = $this->linkedOffsetAllocatedTotal() + $this->linkedPurchaseOffsetAllocatedTotal();
        $allocated = round($bankAllocated + $offsetAllocated, 2);
        $total = round((float) ($this->sum_inc_vat ?? 0), 2);
        $absTotal = abs($total);

        if ($absTotal < 0.005) {
            $this->paid_amount = 0;
            $this->paid = false;

            return true;
        }

        if ($total < 0) {
            if ($allocated + 0.02 >= $absTotal) {
                $this->paid_amount = $total;
                $this->paid = true;
            } else {
                $this->paid_amount = round(-min($absTotal, $allocated), 2);
                $this->paid = false;
            }

            return true;
        }

        if ($allocated + 0.02 >= $total) {
            $this->paid_amount = $total;
            $this->paid = true;
        } else {
            $this->paid_amount = max(0, min($total, $allocated));
            $this->paid = false;
        }

        return true;
    }

    /**
     * Persist paid/partly-paid status from current bank payment + offset links.
     */
    public function resyncPaymentFromBankLinks(): bool
    {
        if (! $this->applyBankLinkPaymentState()) {
            return false;
        }

        $this->normalizePaymentState();
        $this->save();

        return true;
    }

    /**
     * Amount in invoice currency for a bank payment, without depending on current paid_amount.
     */
    public function convertBankAmountToInvoiceCurrency(float $bankAmount, ?string $bankCurrency = 'EUR'): float
    {
        $bankAmount = round(abs($bankAmount), 2);
        $bankCurrency = Currency::normalize($bankCurrency ?? 'EUR');
        $invoiceCurrency = $this->currencyCode();

        if ($bankCurrency === $invoiceCurrency) {
            return $bankAmount;
        }

        if ($bankCurrency === 'EUR') {
            $total = round((float) ($this->sum_inc_vat ?? 0), 2);
            $eurTotal = $this->amountInEur();
            if ($total >= 0.005 && abs($bankAmount - $eurTotal) < 0.02) {
                return $total;
            }

            return Currency::fromEur(
                $bankAmount,
                $invoiceCurrency,
                $this->currency_rate !== null ? (float) $this->currency_rate : null,
            ) ?? 0.0;
        }

        $eur = Currency::toEur($bankAmount, $bankCurrency);
        if ($eur === null) {
            return 0.0;
        }

        if ($invoiceCurrency === 'EUR') {
            return $eur;
        }

        return Currency::fromEur(
            $eur,
            $invoiceCurrency,
            $this->currency_rate !== null ? (float) $this->currency_rate : null,
        ) ?? 0.0;
    }

    public function isOverdue(mixed $asOf = null): bool
    {
        if ($this->isFullyPaid()) {
            return false;
        }

        $due = $this->dueDate();
        if ($due === null) {
            return false;
        }

        $asOfDate = $asOf instanceof Carbon
            ? $asOf->copy()->startOfDay()
            : now()->copy()->startOfDay();

        return $due->lt($asOfDate);
    }

    public static function amountsAreFullyPaid(mixed $paidAmount, mixed $total): bool
    {
        $paid = round((float) $paidAmount, 2);
        $sum = round((float) $total, 2);

        return abs($sum) >= 0.005 && abs($paid - $sum) < 0.005;
    }

    public static function paymentTermFromCustomer(?User $customer): ?int
    {
        if ($customer !== null && $customer->payment_term !== null) {
            $days = (int) $customer->payment_term;

            return $days >= 0 ? $days : self::DEFAULT_PAYMENT_TERM_DAYS;
        }

        return self::DEFAULT_PAYMENT_TERM_DAYS;
    }

    /**
     * Resolve payment term days from form/import input, falling back to the company default.
     */
    public static function resolvePaymentTerm(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_PAYMENT_TERM_DAYS;
        }

        return max(0, (int) $value);
    }

    public function normalizePaymentState(): void
    {
        $this->payment_term = $this->payment_term === null || $this->payment_term === ''
            ? null
            : max(0, (int) $this->payment_term);

        $total = round((float) $this->sum_inc_vat, 2);
        $paidAmount = round((float) ($this->paid_amount ?? 0), 2);

        if ((bool) $this->paid && ! self::amountsAreFullyPaid($paidAmount, $total)) {
            $paidAmount = $total;
        }

        $this->paid_amount = $paidAmount;
        $this->paid = self::amountsAreFullyPaid($paidAmount, $total);
    }

    public function fileUrl(): string
    {
        return $this->primarySharepointUrl()
            ?? $this->languagePdfUrl('lt')
            ?? $this->languagePdfUrl('en')
            ?? '#';
    }

    /**
     * @return list<array{name: string, path: string, item_id: ?string, web_url: ?string, original_name?: string, uploaded_at?: string}>
     */
    public function additionalDocuments(): array
    {
        if (! is_array($this->additional_documents)) {
            return [];
        }

        return array_values(array_filter(
            $this->additional_documents,
            fn ($file): bool => is_array($file) && filled($file['path'] ?? $file['web_url'] ?? $file['name'] ?? null),
        ));
    }

    public function hasAdditionalDocuments(): bool
    {
        return $this->additionalDocuments() !== [];
    }

    /**
     * Manually marked as history — documents (CMR) are not required.
     */
    public function isHistoricalRecord(): bool
    {
        return (bool) $this->is_history;
    }

    /**
     * Sales invoice to a non-LT (export) customer — CMR proof of delivery is expected.
     */
    public function isExportSale(): bool
    {
        if ($this->is_own_account || $this->income_type_id === null) {
            return false;
        }

        $this->loadMissing(['contact', 'order.user']);

        $user = $this->order?->user;
        if ($user !== null && $user->export !== null) {
            return (bool) $user->export;
        }

        return ! $this->customerIsLithuania();
    }

    public function customerIsLithuania(): bool
    {
        $this->loadMissing(['contact', 'order.user']);

        $user = $this->order?->user;
        if ($user !== null && $user->export === false) {
            return true;
        }

        $country = mb_strtolower(trim((string) (
            $this->contact?->company_country
            ?: $user?->company_country
            ?: ''
        )));

        if (in_array($country, ['lithuania', 'lietuva', 'lt'], true)) {
            return true;
        }

        $vat = strtoupper(preg_replace('/\s+/', '', (string) (
            $this->contact?->company_vat
            ?: $user?->company_vat
            ?: ''
        )) ?? '');

        return str_starts_with($vat, 'LT');
    }

    public function requiresCmrDocument(): bool
    {
        return ! $this->isHistoricalRecord() && $this->isExportSale();
    }

    public function isMissingCmrDocument(): bool
    {
        return $this->requiresCmrDocument() && ! $this->hasAdditionalDocuments();
    }

    public function additionalDocumentLabel(array $file): string
    {
        $original = trim((string) ($file['original_name'] ?? ''));
        if ($original !== '') {
            return $original;
        }

        $name = trim((string) ($file['name'] ?? ''));

        return $name !== '' ? $name : 'Document';
    }

    public function additionalDocumentUrl(array $file): ?string
    {
        $url = trim((string) ($file['web_url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    public function hasFinanceDetailsWarning(): bool
    {
        $this->loadMissing('financeLines');

        if ($this->financeLines->isEmpty()) {
            return true;
        }

        $creditTotal = round((float) $this->financeLines->sum('credit'), 2);
        $debitTotal = round((float) $this->financeLines->sum('debit'), 2);

        return abs($creditTotal - $debitTotal) >= 0.005;
    }

    public function financeDetailsWarningMessage(): string
    {
        $this->loadMissing('financeLines');

        if ($this->financeLines->isEmpty()) {
            return 'No finance lines entered.';
        }

        $creditTotal = round((float) $this->financeLines->sum('credit'), 2);
        $debitTotal = round((float) $this->financeLines->sum('debit'), 2);

        if (abs($creditTotal - $debitTotal) >= 0.005) {
            return 'Debit and credit totals are not equal.';
        }

        return 'Finance details need attention.';
    }
}
