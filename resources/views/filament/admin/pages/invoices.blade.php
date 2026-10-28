<x-filament-panels::page>
    <div class="invoices-page">
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
        {{ $this->filtersForm }}

        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-arrow-path"
                    wire:click="refreshList"
                    wire:loading.attr="disabled"
                >
                    Refresh
                </x-filament::button>
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-x-mark"
                    wire:click="resetFilters"
                    wire:loading.attr="disabled"
                >
                    Reset
                </x-filament::button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button
                    type="button"
                    color="primary"
                    icon="heroicon-o-magnifying-glass-circle"
                    wire:click="scanSuggestedPayments"
                    wire:loading.attr="disabled"
                >
                    Scan for payments
                </x-filament::button>
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-link"
                    wire:click="confirmAllSuggestedPayments"
                    wire:confirm="Link every invoice that currently has a suggested bank payment?"
                    wire:loading.attr="disabled"
                >
                    Confirm all suggested
                </x-filament::button>
            </div>
        </div>
    </div>

    <div class="invoice-table-wrap invoices-table-wrap rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        @php
            $invoices = $this->getInvoices();
            $categoryGroups = $this->financeCategorySelectOptions();
        @endphp
        <table class="invoice-table invoices-table w-full text-sm">
            <colgroup>
                <col class="inv-col-check" />
                <col class="inv-col-buyer" />
                <col class="inv-col-number" />
                <col class="inv-col-date" />
                <col class="inv-col-sum" />
                <col class="inv-col-sum" />
                <col class="inv-col-paid" />
                <col class="inv-col-bank" />
                <col class="inv-col-category" />
                <col class="inv-col-comment" />
                <col class="inv-col-docs" />
                <col class="inv-col-actions" />
            </colgroup>
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="inv-th inv-th--check">
                        <input
                            type="checkbox"
                            wire:click.prevent="toggleSelectAllInvoices"
                            @checked($this->selectAllInvoices)
                            @disabled($invoices->isEmpty())
                            class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                            title="Select all"
                        >
                    </th>
                    @php
                        $sortableHeaders = [
                            'buyer' => ['label' => 'Customer / supplier', 'align' => 'left'],
                            'invoice_number' => ['label' => 'Invoice no', 'align' => 'left'],
                        ];
                    @endphp
                    @foreach ($sortableHeaders as $column => $meta)
                        <th class="inv-th text-{{ $meta['align'] }}">
                            <button
                                type="button"
                                wire:click="sortBy('{{ $column }}')"
                                class="inline-flex items-center gap-1 hover:text-primary-600 dark:hover:text-primary-400"
                                title="Sort by {{ $meta['label'] }}"
                            >
                                <span>{{ $meta['label'] }}</span>
                                @if ($this->isSortedBy($column))
                                    @if ($this->sortDirectionFor($column) === 'asc')
                                        <x-heroicon-m-chevron-up class="h-3.5 w-3.5" />
                                    @else
                                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5" />
                                    @endif
                                @else
                                    <x-heroicon-m-chevron-up-down class="h-3.5 w-3.5 text-gray-400" />
                                @endif
                            </button>
                        </th>
                    @endforeach
                    <th class="inv-th">
                        <button
                            type="button"
                            wire:click="sortBy('invoice_date')"
                            class="inline-flex items-center gap-1 hover:text-primary-600 dark:hover:text-primary-400"
                            title="Sort by invoice date"
                        >
                            <span>Date</span>
                            @if ($this->isSortedBy('invoice_date'))
                                @if ($this->sortDirectionFor('invoice_date') === 'asc')
                                    <x-heroicon-m-chevron-up class="h-3.5 w-3.5" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5" />
                                @endif
                            @else
                                <x-heroicon-m-chevron-up-down class="h-3.5 w-3.5 text-gray-400" />
                            @endif
                        </button>
                    </th>
                    <th class="inv-th inv-th--num">
                        <button
                            type="button"
                            wire:click="sortBy('sum_without_vat')"
                            class="inline-flex items-center gap-1 hover:text-primary-600 dark:hover:text-primary-400"
                            title="Sort by sum"
                        >
                            <span>No VAT</span>
                            @if ($this->isSortedBy('sum_without_vat'))
                                @if ($this->sortDirectionFor('sum_without_vat') === 'asc')
                                    <x-heroicon-m-chevron-up class="h-3.5 w-3.5" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5" />
                                @endif
                            @else
                                <x-heroicon-m-chevron-up-down class="h-3.5 w-3.5 text-gray-400" />
                            @endif
                        </button>
                    </th>
                    <th class="inv-th inv-th--num">
                        <button
                            type="button"
                            wire:click="sortBy('sum_inc_vat')"
                            class="inline-flex items-center gap-1 hover:text-primary-600 dark:hover:text-primary-400"
                            title="Sort by total"
                        >
                            <span>Total</span>
                            @if ($this->isSortedBy('sum_inc_vat'))
                                @if ($this->sortDirectionFor('sum_inc_vat') === 'asc')
                                    <x-heroicon-m-chevron-up class="h-3.5 w-3.5" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5" />
                                @endif
                            @else
                                <x-heroicon-m-chevron-up-down class="h-3.5 w-3.5 text-gray-400" />
                            @endif
                        </button>
                    </th>
                    <th class="inv-th">
                        <button
                            type="button"
                            wire:click="sortBy('paid')"
                            class="inline-flex items-center gap-1 hover:text-primary-600 dark:hover:text-primary-400"
                            title="Sort by paid"
                        >
                            <span>Paid</span>
                            @if ($this->isSortedBy('paid'))
                                @if ($this->sortDirectionFor('paid') === 'asc')
                                    <x-heroicon-m-chevron-up class="h-3.5 w-3.5" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5" />
                                @endif
                            @else
                                <x-heroicon-m-chevron-up-down class="h-3.5 w-3.5 text-gray-400" />
                            @endif
                        </button>
                    </th>
                    <th class="inv-th">Bank</th>
                    <th class="inv-th">
                        <button
                            type="button"
                            wire:click="sortBy('category')"
                            class="inline-flex items-center gap-1 hover:text-primary-600 dark:hover:text-primary-400"
                            title="Sort by category"
                        >
                            <span>Category</span>
                            @if ($this->isSortedBy('category'))
                                @if ($this->sortDirectionFor('category') === 'asc')
                                    <x-heroicon-m-chevron-up class="h-3.5 w-3.5" />
                                @else
                                    <x-heroicon-m-chevron-down class="h-3.5 w-3.5" />
                                @endif
                            @else
                                <x-heroicon-m-chevron-up-down class="h-3.5 w-3.5 text-gray-400" />
                            @endif
                        </button>
                    </th>
                    <th class="inv-th">Comment</th>
                    <th class="inv-th" title="Mark History when CMR is not needed. Export sales otherwise need CMR.">CMR</th>
                    <th class="inv-th">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-900">
                @if ($invoices->isEmpty())
                    <tr>
                        <td colspan="12" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                            @if ($this->hasActiveInvoiceFilters())
                                No invoices match your filters.
                            @else
                                No invoices uploaded yet.
                            @endif
                        </td>
                    </tr>
                @else
                @foreach ($invoices as $invoice)
                    @php
                        $hasFinanceWarning = $invoice->hasFinanceDetailsWarning();
                        $financeKind = $invoice->isSupplierCreditNote()
                            ? 'income'
                            : $invoice->financeCategoryKind();
                        $amountKind = $invoice->displayAmountKind();
                    @endphp
                    <tr @class([
                        'invoice-row--warning' => $hasFinanceWarning,
                        'invoice-row--income bg-success-50/70 dark:bg-success-950/25' => $financeKind === 'income',
                        'invoice-row--expense bg-rose-50/80 dark:bg-rose-950/30' => $financeKind === 'expense',
                        'invoice-row--own-account bg-slate-50/90 dark:bg-slate-900/40' => $financeKind === 'own_account',
                        'invoice-row--missing' => $financeKind === null,
                    ])>
                        <td class="inv-td inv-td--check">
                            <input
                                type="checkbox"
                                value="{{ $invoice->getKey() }}"
                                wire:model.live="selectedInvoiceIds"
                                wire:key="invoice-check-{{ $invoice->getKey() }}"
                                class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                            >
                        </td>
                        <td class="inv-td inv-td--buyer">
                            <div class="flex items-start gap-1.5 min-w-0">
                                @if ($hasFinanceWarning)
                                    <span
                                        class="mt-0.5 inline-flex shrink-0 text-danger-600 dark:text-danger-400"
                                        title="{{ $invoice->financeDetailsWarningMessage() }}"
                                    >
                                        <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
                                    </span>
                                @endif
                                <span class="inv-clamp" title="{{ $invoice->counterpartLabel() }}">{{ $invoice->counterpartLabel() }}</span>
                            </div>
                        </td>
                        <td class="inv-td">
                            <div
                                wire:key="inv-number-{{ $invoice->getKey() }}"
                                class="bs-inline-edit"
                                x-data="{
                                    editing: false,
                                    saving: false,
                                    value: @js($invoice->invoice_number),
                                    draft: '',
                                    start() {
                                        this.draft = this.value ?? '';
                                        this.editing = true;
                                        this.$nextTick(() => this.$refs.input?.focus());
                                    },
                                    cancel() {
                                        this.editing = false;
                                        this.saving = false;
                                    },
                                    async save() {
                                        const next = (this.draft ?? '').trim();
                                        const current = (this.value ?? '').trim();
                                        if (next === current) {
                                            this.editing = false;
                                            return;
                                        }
                                        this.saving = true;
                                        try {
                                            const saved = await $wire.updateInvoiceNumber({{ $invoice->getKey() }}, next === '' ? null : next);
                                            if (! saved) {
                                                return;
                                            }
                                            this.value = next === '' ? null : next;
                                            this.editing = false;
                                        } finally {
                                            this.saving = false;
                                        }
                                    }
                                }"
                            >
                                <button
                                    type="button"
                                    x-show="! editing"
                                    @click="start()"
                                    class="bs-inline-edit__trigger text-left"
                                    title="Edit invoice number"
                                >
                                    <span x-show="value" x-text="value" class="bs-inline-edit__text inv-clamp text-primary-600 dark:text-primary-400" :title="value || ''"></span>
                                    <span x-show="! value" class="bs-inline-edit__placeholder">Add number</span>
                                </button>
                                <div x-show="editing" x-cloak class="bs-inline-edit__editor">
                                    <input
                                        type="text"
                                        x-ref="input"
                                        x-model="draft"
                                        maxlength="255"
                                        class="bs-inline-edit__input"
                                        @keydown.enter.prevent="save()"
                                        @keydown.escape.prevent="cancel()"
                                    />
                                    <div class="bs-inline-edit__actions">
                                        <button type="button" class="bs-inline-edit__save" @click="save()" :disabled="saving">Save</button>
                                        <button type="button" class="bs-inline-edit__cancel" @click="cancel()" :disabled="saving">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="inv-td inv-td--date">
                            {{ optional($invoice->invoice_date)->format('Y-m-d') }}
                        </td>
                        <td @class([
                            'inv-td inv-td--num font-medium tabular-nums',
                            'text-success-700 dark:text-success-400' => $amountKind === 'income',
                            'text-rose-700 dark:text-rose-400' => $amountKind === 'expense',
                            'text-slate-700 dark:text-slate-300' => $financeKind === 'own_account',
                            'text-gray-700 dark:text-gray-200' => $amountKind === null && $financeKind !== 'own_account',
                        ])>
                            @if ($amountKind === 'income')
                                +{{ \App\Support\Money::format($invoice->sum_without_vat, 2, $invoice->currencyCode()) }}
                            @elseif ($amountKind === 'expense')
                                −{{ \App\Support\Money::format($invoice->sum_without_vat, 2, $invoice->currencyCode()) }}
                            @else
                                {{ \App\Support\Money::format($invoice->sum_without_vat, 2, $invoice->currencyCode()) }}
                            @endif
                        </td>
                        <td @class([
                            'inv-td inv-td--num font-medium tabular-nums',
                            'text-success-700 dark:text-success-400' => $amountKind === 'income',
                            'text-rose-700 dark:text-rose-400' => $amountKind === 'expense',
                            'text-slate-700 dark:text-slate-300' => $financeKind === 'own_account',
                            'text-gray-700 dark:text-gray-200' => $amountKind === null && $financeKind !== 'own_account',
                        ])>
                            @if ($amountKind === 'income')
                                +{{ \App\Support\Money::format($invoice->sum_inc_vat, 2, $invoice->currencyCode()) }}
                            @elseif ($amountKind === 'expense')
                                −{{ \App\Support\Money::format($invoice->sum_inc_vat, 2, $invoice->currencyCode()) }}
                            @else
                                {{ \App\Support\Money::format($invoice->sum_inc_vat, 2, $invoice->currencyCode()) }}
                            @endif
                            @if (! $invoice->isEuroCurrency() && $invoice->sum_inc_vat_eur !== null)
                                <div class="text-[10px] font-normal text-gray-500 dark:text-gray-400">
                                    ≈ {{ \App\Support\Money::format($invoice->sum_inc_vat_eur, 2, 'EUR') }}
                                </div>
                            @endif
                        </td>
                        <td class="inv-td">
                            @if ($invoice->isFullyPaid())
                                <button
                                    type="button"
                                    wire:click="markInvoiceUnpaid({{ $invoice->getKey() }})"
                                    wire:confirm="Mark this invoice as unpaid? Paid amount will be cleared."
                                    class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium text-success-600 transition hover:bg-success-50 dark:text-success-400 dark:hover:bg-success-950/40"
                                    title="Paid {{ \App\Support\Money::format($invoice->paid_amount, 2, $invoice->currencyCode()) }} — click to mark unpaid"
                                >
                                    <x-heroicon-o-check class="h-4 w-4" />
                                    Yes
                                </button>
                            @elseif ($invoice->isPartlyPaid())
                                <div class="inline-flex flex-col items-start gap-1">
                                    <span
                                        class="inline-flex items-center gap-1 font-medium text-warning-600 dark:text-warning-400"
                                        title="{{ \App\Support\Money::format($invoice->paid_amount, 2, $invoice->currencyCode()) }} of {{ \App\Support\Money::format($invoice->sum_inc_vat, 2, $invoice->currencyCode()) }}"
                                    >
                                        <x-heroicon-o-minus-circle class="h-4 w-4" />
                                        Partly
                                    </span>
                                    <button
                                        type="button"
                                        wire:click="markInvoicePaid({{ $invoice->getKey() }})"
                                        wire:confirm="Mark as fully paid? Paid amount will be set to the invoice total."
                                        class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                    >
                                        Mark paid
                                    </button>
                                </div>
                            @else
                                <button
                                    type="button"
                                    wire:click="markInvoicePaid({{ $invoice->getKey() }})"
                                    wire:confirm="Mark as paid? Paid amount will be set to {{ \App\Support\Money::format($invoice->sum_inc_vat, 2, $invoice->currencyCode()) }}."
                                    class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                    title="Mark as paid"
                                >
                                    Mark paid
                                </button>
                            @endif
                        </td>
                        <td class="inv-td bs-invoice-cell">
                            @php
                                $linkedPayments = $invoice->linkedBankStatementTransactions();
                                $suggestedPayment = $invoice->relationLoaded('suggestedBankPayment')
                                    ? $invoice->getRelation('suggestedBankPayment')
                                    : null;
                                $salesOffsets = collect();
                                $purchaseOffsets = collect();
                                if (\Illuminate\Support\Facades\Schema::hasTable('invoice_offset_links')) {
                                    $salesOffsets = $invoice->relationLoaded('salesOffsetLinks')
                                        ? $invoice->salesOffsetLinks
                                        : $invoice->salesOffsetLinks()->with('purchaseInvoice')->get();
                                    $purchaseOffsets = $invoice->relationLoaded('purchaseOffsetLinks')
                                        ? $invoice->purchaseOffsetLinks
                                        : $invoice->purchaseOffsetLinks()->with('salesInvoice')->get();
                                }
                            @endphp
                            <div class="bs-invoice-linked">
                                @if ($linkedPayments->isNotEmpty() || $salesOffsets->isNotEmpty() || $purchaseOffsets->isNotEmpty())
                                    <div class="flex flex-col gap-1">
                                        @foreach ($linkedPayments as $payment)
                                            @php
                                                $paymentLabel = trim(implode(' · ', array_filter([
                                                    optional($payment->booking_date)->format('Y-m-d'),
                                                    \App\Support\Money::format(abs((float) $payment->amount), 2, \App\Models\Currency::normalize($payment->currency ?? 'EUR')),
                                                ])));
                                            @endphp
                                            <div class="flex items-center gap-2">
                                                <div class="bs-invoice-link bs-invoice-link--static" title="{{ $payment->counterpart_name ?: 'Bank payment' }}">
                                                    <x-heroicon-o-banknotes class="bs-invoice-link__icon" />
                                                    <span>
                                                        {{ $paymentLabel !== '' ? $paymentLabel : '#'.$payment->getKey() }}
                                                    </span>
                                                </div>
                                                <button
                                                    type="button"
                                                    wire:click="unlinkBankPayment({{ $invoice->getKey() }}, {{ $payment->getKey() }})"
                                                    wire:confirm="Unlink this bank payment from the invoice?"
                                                    class="bs-invoice-quiet"
                                                >
                                                    Unlink
                                                </button>
                                            </div>
                                        @endforeach
                                        @foreach ($salesOffsets as $offset)
                                            @php
                                                $purchase = $offset->purchaseInvoice;
                                                $offsetLabel = trim(implode(' · ', array_filter([
                                                    $offset->operation_ref,
                                                    $purchase?->invoice_number ?: ('#'.$offset->purchase_invoice_id),
                                                    \App\Support\Money::format((float) $offset->amount),
                                                ])));
                                            @endphp
                                            <div class="bs-invoice-link bs-invoice-link--static" title="Offset / užskaita vs purchase {{ $purchase?->invoice_number }}">
                                                <x-heroicon-o-arrows-right-left class="bs-invoice-link__icon" />
                                                <span>{{ $offsetLabel }}</span>
                                            </div>
                                        @endforeach
                                        @foreach ($purchaseOffsets as $offset)
                                            @php
                                                $sales = $offset->salesInvoice;
                                                $offsetLabel = trim(implode(' · ', array_filter([
                                                    $offset->operation_ref,
                                                    $sales?->invoice_number ?: ('#'.$offset->sales_invoice_id),
                                                    \App\Support\Money::format((float) $offset->amount),
                                                ])));
                                            @endphp
                                            <div class="bs-invoice-link bs-invoice-link--static" title="Offset / užskaita vs sales {{ $sales?->invoice_number }}">
                                                <x-heroicon-o-arrows-right-left class="bs-invoice-link__icon" />
                                                <span>{{ $offsetLabel }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif ($suggestedPayment)
                                    @php
                                        $suggestedLabel = trim(implode(' · ', array_filter([
                                            optional($suggestedPayment->booking_date)->format('Y-m-d'),
                                            \App\Support\Money::format(abs((float) $suggestedPayment->amount), 2, \App\Models\Currency::normalize($suggestedPayment->currency ?? 'EUR')),
                                            $suggestedPayment->counterpart_name,
                                        ])));
                                    @endphp
                                    <div class="flex flex-col items-start gap-1">
                                        <div class="bs-invoice-link bs-invoice-link--static" title="{{ $suggestedLabel }}">
                                            <x-heroicon-o-sparkles class="bs-invoice-link__icon" />
                                            <span>{{ $suggestedLabel !== '' ? $suggestedLabel : 'Suggested payment' }}</span>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-1">
                                            <button
                                                type="button"
                                                wire:click="confirmSuggestedBankPayment({{ $invoice->getKey() }})"
                                                class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                            >
                                                Confirm
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="mountAction('linkBankPayment', { invoiceId: {{ $invoice->getKey() }} })"
                                                class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
                                            >
                                                Other…
                                            </button>
                                        </div>
                                    </div>
                                @endif
                                @if (! $invoice->isFullyPaid() && $linkedPayments->isEmpty() && $salesOffsets->isEmpty() && $purchaseOffsets->isEmpty() && ! $suggestedPayment)
                                    <button
                                        type="button"
                                        wire:click="mountAction('linkBankPayment', { invoiceId: {{ $invoice->getKey() }} })"
                                        class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                    >
                                        Link
                                    </button>
                                @elseif (! $invoice->isFullyPaid() && ($linkedPayments->isNotEmpty() || $salesOffsets->isNotEmpty() || $purchaseOffsets->isNotEmpty()))
                                    <button
                                        type="button"
                                        wire:click="mountAction('linkBankPayment', { invoiceId: {{ $invoice->getKey() }} })"
                                        class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                    >
                                        Link another
                                    </button>
                                @elseif ($invoice->isFullyPaid() && $linkedPayments->isEmpty() && $salesOffsets->isEmpty() && $purchaseOffsets->isEmpty() && ! $suggestedPayment)
                                    <button
                                        type="button"
                                        wire:click="mountAction('linkBankPayment', { invoiceId: {{ $invoice->getKey() }} })"
                                        class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                    >
                                        Link
                                    </button>
                                @endif
                            </div>
                        </td>
                        <td class="inv-td">
                            @include('filament.admin.pages.partials.invoice-category-select', [
                                'invoice' => $invoice,
                                'categoryGroups' => $categoryGroups,
                            ])
                        </td>
                        <td class="inv-td inv-td--comment">
                            <div
                                wire:key="inv-comment-{{ $invoice->getKey() }}"
                                class="bs-inline-edit"
                                x-data="{
                                    editing: false,
                                    saving: false,
                                    value: @js($invoice->comment),
                                    draft: '',
                                    start() {
                                        this.draft = this.value ?? '';
                                        this.editing = true;
                                        this.$nextTick(() => this.$refs.input?.focus());
                                    },
                                    cancel() {
                                        this.editing = false;
                                        this.saving = false;
                                    },
                                    async save() {
                                        const next = (this.draft ?? '').trim();
                                        const current = (this.value ?? '').trim();
                                        if (next === current) {
                                            this.editing = false;
                                            return;
                                        }
                                        this.saving = true;
                                        try {
                                            await $wire.updateComment({{ $invoice->getKey() }}, next === '' ? null : next);
                                            this.value = next === '' ? null : next;
                                            this.editing = false;
                                        } finally {
                                            this.saving = false;
                                        }
                                    }
                                }"
                            >
                                <button
                                    type="button"
                                    x-show="! editing"
                                    @click="start()"
                                    class="bs-inline-edit__trigger text-left"
                                    title="{{ filled($invoice->comment) ? 'Edit comment' : 'Add comment' }}"
                                >
                                    <span x-show="value" x-text="value" class="bs-inline-edit__text inv-clamp" :title="value || ''"></span>
                                    <span x-show="! value" class="bs-inline-edit__placeholder">Add comment</span>
                                </button>
                                <div x-show="editing" x-cloak class="bs-inline-edit__editor">
                                    <textarea
                                        x-ref="input"
                                        x-model="draft"
                                        rows="2"
                                        maxlength="1000"
                                        class="bs-inline-edit__textarea"
                                        @keydown.ctrl.enter.prevent="save()"
                                        @keydown.meta.enter.prevent="save()"
                                        @keydown.escape.prevent="cancel()"
                                    ></textarea>
                                    <div class="bs-inline-edit__actions">
                                        <button type="button" class="bs-inline-edit__save" @click="save()" :disabled="saving">Save</button>
                                        <button type="button" class="bs-inline-edit__cancel" @click="cancel()" :disabled="saving">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="inv-td">
                            @php
                                $additionalDocuments = $invoice->additionalDocuments();
                                $isHistory = $invoice->isHistoricalRecord();
                                $requiresCmr = $invoice->requiresCmrDocument();
                                $missingCmr = $requiresCmr && $additionalDocuments === [];
                            @endphp
                            <div class="flex flex-col items-start gap-1 min-w-0">
                                <button
                                    type="button"
                                    wire:click="toggleInvoiceHistory({{ $invoice->getKey() }})"
                                    title="{{ $isHistory ? 'Marked as history — CMR not required. Click to undo.' : 'Mark as history — CMR will not be required' }}"
                                    @class([
                                        'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium transition',
                                        'bg-slate-700 text-white dark:bg-slate-200 dark:text-slate-900' => $isHistory,
                                        'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' => ! $isHistory,
                                    ])
                                >
                                    @if ($isHistory)
                                        <x-heroicon-o-check class="h-3.5 w-3.5" />
                                    @endif
                                    History
                                </button>

                                @if ($isHistory)
                                    <span class="text-[11px] text-slate-500 dark:text-slate-400">No CMR needed</span>
                                @elseif ($additionalDocuments === [])
                                    @if ($missingCmr)
                                        <span
                                            class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-300"
                                            title="Export sale — CMR missing"
                                        >
                                            <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5" />
                                            Missing
                                        </span>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="mountAction('uploadAdditionalDocuments', { invoiceId: {{ $invoice->getKey() }} })"
                                        title="{{ $requiresCmr ? 'Upload CMR to SharePoint' : 'Add additional documents' }}"
                                        class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                    >
                                        {{ $requiresCmr ? 'Add CMR' : 'Add' }}
                                    </button>
                                @else
                                    @if ($requiresCmr)
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-success-700 dark:text-success-400" title="CMR attached">
                                            <x-heroicon-o-check-circle class="h-3.5 w-3.5" />
                                            CMR
                                        </span>
                                    @endif
                                    @foreach ($additionalDocuments as $file)
                                        @php
                                            $label = $invoice->additionalDocumentLabel($file);
                                            $url = $invoice->additionalDocumentUrl($file);
                                        @endphp
                                        <div class="min-w-0">
                                            @if ($url)
                                                <a href="{{ $url }}" target="_blank" rel="noopener" class="inv-clamp block text-primary-600 hover:underline dark:text-primary-400" title="{{ $label }}">
                                                    {{ $label }}
                                                </a>
                                            @else
                                                <span class="inv-clamp block" title="{{ $label }}">{{ $label }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                    <button
                                        type="button"
                                        wire:click="mountAction('uploadAdditionalDocuments', { invoiceId: {{ $invoice->getKey() }} })"
                                        title="{{ $requiresCmr ? 'Add more CMR / documents' : 'Add additional documents' }}"
                                        class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                    >
                                        Add more
                                    </button>
                                @endif
                            </div>
                        </td>
                        <td class="inv-td inv-td--actions">
                            <div class="flex flex-nowrap items-center gap-0.5">
                                @if ($invoice->canOpenLanguagePdf())
                                    @php
                                        $ltUrl = $invoice->languagePdfUrl('lt');
                                        $enUrl = $invoice->languagePdfUrl('en');
                                    @endphp
                                    <span class="inline-flex flex-nowrap items-center gap-0.5">
                                        @if ($ltUrl && $enUrl)
                                            <a
                                                href="{{ $ltUrl }}"
                                                target="_blank"
                                                rel="noopener"
                                                title="Open Lithuanian invoice"
                                                class="inline-flex items-center rounded-md px-1.5 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                            >
                                                LT
                                            </a>
                                            <span class="text-xs text-gray-400">/</span>
                                            <a
                                                href="{{ $enUrl }}"
                                                target="_blank"
                                                rel="noopener"
                                                title="Open English invoice"
                                                class="inline-flex items-center rounded-md px-1.5 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                            >
                                                EN
                                            </a>
                                        @endif
                                    </span>
                                @elseif ($invoice->primarySharepointUrl())
                                    <a
                                        href="{{ $invoice->primarySharepointUrl() }}"
                                        target="_blank"
                                        rel="noopener"
                                        title="Open PDF"
                                        class="inline-flex items-center rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-primary-600 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-primary-400"
                                    >
                                        <x-heroicon-o-document-text class="h-4 w-4" />
                                    </a>
                                @endif

                                <button
                                    type="button"
                                    wire:click="mountAction('invoiceDetails', { invoiceId: {{ $invoice->getKey() }} })"
                                    title="Edit invoice"
                                    class="inline-flex items-center rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-primary-600 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-primary-400"
                                >
                                    <x-heroicon-o-pencil-square class="h-4 w-4" />
                                </button>

                                <button
                                    type="button"
                                    wire:click="mountAction('sendInvoiceEmail', { invoiceId: {{ $invoice->getKey() }} })"
                                    title="Send email"
                                    class="inline-flex items-center rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-primary-600 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-primary-400"
                                >
                                    <x-heroicon-o-paper-airplane class="h-4 w-4" />
                                </button>

                                @if ($invoice->order_id)
                                    <button
                                        type="button"
                                        wire:click="regeneratePdf({{ $invoice->getKey() }})"
                                        wire:loading.attr="disabled"
                                        wire:target="regeneratePdf({{ $invoice->getKey() }})"
                                        title="Regenerate PDF"
                                        class="inline-flex items-center rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-primary-600 disabled:opacity-50 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-primary-400"
                                    >
                                        <x-heroicon-o-arrow-path
                                            wire:loading.remove
                                            wire:target="regeneratePdf({{ $invoice->getKey() }})"
                                            class="h-4 w-4"
                                        />
                                        <x-heroicon-o-arrow-path
                                            wire:loading
                                            wire:target="regeneratePdf({{ $invoice->getKey() }})"
                                            class="h-4 w-4 animate-spin"
                                        />
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                @endif
            </tbody>
        </table>
    </div>

    @php
        $summary = $this->summary();
    @endphp
    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Invoice summary',
        'description' => $this->hasActiveInvoiceFilters()
            ? 'Totals for the filtered invoice list.'
            : 'Totals for all invoices.',
        'footer' => number_format($summary['count']).' invoice(s)',
        'stats' => [
            [
                'label' => 'Invoices',
                'value' => number_format($summary['count']),
                'hint' => 'In current list',
                'emphasis' => true,
            ],
            [
                'label' => 'Income (no VAT)',
                'value' => '+'.\App\Support\Money::format($summary['income_sum']),
                'tone' => 'income',
            ],
            [
                'label' => 'Expense (no VAT)',
                'value' => '−'.\App\Support\Money::format($summary['expense_sum']),
                'tone' => 'expense',
            ],
            [
                'label' => 'Sum (no VAT)',
                'value' => \App\Support\Money::format($summary['sum_without_vat']),
            ],
            [
                'label' => 'Sum (total)',
                'value' => \App\Support\Money::format($summary['sum_inc_vat']),
            ],
            [
                'label' => 'Paid amount',
                'value' => \App\Support\Money::format($summary['paid_amount']),
            ],
            [
                'label' => 'Outstanding',
                'value' => \App\Support\Money::format($summary['outstanding']),
                'hint' => 'Left to pay',
            ],
            [
                'label' => 'Missing CMR',
                'value' => number_format($summary['missing_cmr'] ?? 0),
                'hint' => 'Export sales without CMR',
                'tone' => ($summary['missing_cmr'] ?? 0) > 0 ? 'expense' : null,
            ],
        ],
        'class' => 'mt-6',
    ])
    </div>
</x-filament-panels::page>
