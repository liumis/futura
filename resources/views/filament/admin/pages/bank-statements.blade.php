<x-filament-panels::page>
    <div class="bank-statements-page">
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
        <form wire:submit="import" class="space-y-3">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit" size="lg">
                    Upload
                </x-filament::button>
            </div>
        </form>
    </div>

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
                    wire:click="scanSuggestedLinks"
                    wire:loading.attr="disabled"
                >
                    Scan for links
                </x-filament::button>
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-link"
                    wire:click="confirmAllSuggestedLinks"
                    wire:confirm="Link every bank payment that currently has a suggested invoice or contract?"
                    wire:loading.attr="disabled"
                >
                    Confirm all suggested
                </x-filament::button>
            </div>
        </div>
    </div>

        @php($monthGroups = $this->getMonthGroups())
        @php($expandMonths = $this->hasActiveFilters() || $monthGroups->count() <= 1)
        @php($categoryGroups = $this->financeCategorySelectOptions())

    @forelse ($monthGroups as $monthKey => $transactions)
        @php($summary = $this->monthSummary($transactions))
        <div
            class="mb-4 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
            x-data="{ open: {{ ($expandMonths || $loop->first) ? 'true' : 'false' }} }"
        >
            <button
                type="button"
                class="flex w-full flex-wrap items-center justify-between gap-3 bg-gray-50 px-4 py-3 text-left hover:bg-gray-100 dark:bg-gray-800 dark:hover:bg-gray-700"
                @click="open = ! open"
            >
                <div>
                    <div class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $this->monthTitle((string) $monthKey) }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $summary['count'] }} line(s)
                        @if (filled($summary['banks']))
                            · {{ $summary['banks'] }}
                        @endif
                        @php($unlinkedCount = $transactions->filter(fn ($row) => ! $row->isResolved())->count())
                        @php($suggestedCount = $transactions->filter(fn ($row) => ! $row->isResolved() && ($row->suggestedInvoice || $row->suggestedPayment || $row->suggestedSalaryPayment || $row->suggestedBusinessTrip))->count())
                        @if ($unlinkedCount > 0)
                            · <span class="font-medium text-amber-600 dark:text-amber-400">{{ $unlinkedCount }} unlinked</span>
                        @endif
                        @if ($suggestedCount > 0)
                            · <span class="font-medium text-sky-600 dark:text-sky-400">{{ $suggestedCount }} suggested</span>
                        @endif
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Income</span>
                        <div class="font-medium tabular-nums text-gray-950 dark:text-white">{{ $this->formatMoney($summary['income']) }}</div>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Outcome</span>
                        <div class="font-medium tabular-nums text-gray-950 dark:text-white">{{ $this->formatMoney($summary['outcome']) }}</div>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Net</span>
                        <div class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ $this->formatMoney($summary['net']) }}</div>
                    </div>
                    <x-heroicon-o-chevron-down
                        class="h-4 w-4 text-gray-500 transition-transform dark:text-gray-400"
                        x-bind:class="open ? 'rotate-180' : ''"
                    />
                </div>
            </button>

            <div x-show="open" x-cloak class="invoice-table-wrap bank-statements-table-wrap border-t border-gray-200 dark:border-gray-700">
                @if ($suggestedCount > 0)
                    <div class="flex justify-end border-b border-gray-200 px-3 py-2 dark:border-gray-700">
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="gray"
                            wire:click="confirmAllSuggestedInvoices('{{ $monthKey }}')"
                            wire:confirm="Link {{ $suggestedCount }} suggested invoice(s), contract(s), or salary payment(s) for {{ $this->monthTitle((string) $monthKey) }}?"
                        >
                            Confirm {{ $suggestedCount }} suggested
                        </x-filament::button>
                    </div>
                @endif
                <table class="invoice-table bank-statements-table w-full text-sm">
                    <colgroup>
                        <col class="bs-col-date" />
                        <col class="bs-col-party" />
                        <col class="bs-col-party" />
                        <col class="bs-col-desc" />
                        <col class="bs-col-linked" />
                        <col class="bs-col-category" />
                        <col class="bs-col-status" />
                        <col class="bs-col-amount" />
                        <col class="bs-col-amount" />
                    </colgroup>
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="bs-th">Date</th>
                            <th class="bs-th">From</th>
                            <th class="bs-th">To</th>
                            <th class="bs-th">Description</th>
                            <th class="bs-th">Linked</th>
                            <th class="bs-th">Category</th>
                            <th class="bs-th">Status</th>
                            <th class="bs-th bs-th--num">In</th>
                            <th class="bs-th bs-th--num">Out</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-900">
                        @foreach ($transactions as $transaction)
                            <tr
                                wire:key="bs-tx-{{ $transaction->getKey() }}"
                                @class([
                                    'invoice-row--own-account' => $transaction->isInternalTransfer(),
                                    'invoice-row--income' => ! $transaction->isInternalTransfer() && $transaction->direction?->isIncome(),
                                    'invoice-row--expense' => ! $transaction->isInternalTransfer() && ! $transaction->direction?->isIncome(),
                                ])
                            >
                                <td class="bs-td bs-td--date">
                                    <div class="bs-date-stack">
                                        <span class="bs-date-stack__pay" title="Payment date">
                                            {{ optional($transaction->booking_date)->format('Y-m-d') }}
                                        </span>
                                        @php($invoiceDate = $this->linkedDocumentDate($transaction))
                                        @if (filled($invoiceDate))
                                            <span class="bs-date-stack__inv" title="Invoice / document date">{{ $invoiceDate }}</span>
                                        @endif
                                        @if ($transaction->import?->primarySharepointUrl())
                                            <a
                                                href="{{ $transaction->import->primarySharepointUrl() }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title="{{ $transaction->import->sharepoint_path ?: 'Open statement PDF' }}"
                                                class="bs-date-stack__file"
                                            >
                                                <x-heroicon-o-document-text class="h-3.5 w-3.5" />
                                            </a>
                                        @endif
                                        @if ($transaction->invoice)
                                            <a
                                                href="{{ $this->invoiceEditUrl($transaction->invoice) }}"
                                                title="Edit invoice {{ $transaction->invoice->displayNumber() }}"
                                                class="bs-date-stack__file"
                                            >
                                                <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                            </a>
                                        @elseif ($transaction->document)
                                            <a
                                                href="{{ $this->documentEditUrl($transaction->document) }}"
                                                title="Edit document {{ $transaction->document->name }}"
                                                class="bs-date-stack__file"
                                            >
                                                <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                            </a>
                                        @endif
                                    </div>
                                </td>
                                <td class="bs-td bs-td--party">
                                    @include('filament.admin.pages.partials.bank-statement-party', [
                                        'transaction' => $transaction,
                                        'party' => $transaction->fromParty(),
                                    ])
                                </td>
                                <td class="bs-td bs-td--party">
                                    @include('filament.admin.pages.partials.bank-statement-party', [
                                        'transaction' => $transaction,
                                        'party' => $transaction->toParty(),
                                    ])
                                </td>
                                <td class="bs-td bs-td--desc">
                                    <div
                                        wire:key="bs-desc-{{ $transaction->getKey() }}"
                                        class="bs-inline-edit"
                                        x-data="{
                                            editing: false,
                                            saving: false,
                                            value: @js($transaction->description),
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
                                                    await $wire.updateDescription({{ $transaction->getKey() }}, next === '' ? null : next);
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
                                            class="bs-inline-edit__trigger"
                                            title="Edit description"
                                        >
                                            <span x-show="value" x-text="value" class="bs-inline-edit__text bs-clamp-2"></span>
                                            <span x-show="! value" class="bs-inline-edit__placeholder">Add description</span>
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
                                <td class="bs-td bs-invoice-cell">
                                    <div class="bs-linked-stack">
                                    <div class="bs-linked-stack__doc">
                                    @php($linkedBatch = $transaction->linkedInvoices())
                                    @if ($linkedBatch->isNotEmpty())
                                        <div class="bs-invoice-linked bs-invoice-linked--stack">
                                            <div class="bs-invoice-linked__list">
                                                @foreach ($linkedBatch as $linkedInvoice)
                                                    <div class="bs-invoice-link-row">
                                                        <div class="bs-invoice-link" title="{{ $linkedInvoice->sharepoint_path ?: $linkedInvoice->displayNumber() }}">
                                                            <x-heroicon-o-document-text class="bs-invoice-link__icon" />
                                                            {!! $linkedInvoice->openDocumentLinksHtml() !!}
                                                            <a
                                                                href="{{ $this->invoiceEditUrl($linkedInvoice) }}"
                                                                class="bs-invoice-quiet"
                                                                title="Edit invoice"
                                                            >
                                                                <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                                            </a>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            wire:click="unlinkInvoice({{ $transaction->getKey() }}, {{ $linkedInvoice->getKey() }})"
                                                            wire:confirm="Unlink {{ $linkedInvoice->displayNumber() }} from this payment?"
                                                            class="bs-invoice-quiet bs-invoice-link-row__unlink"
                                                            title="Unlink this invoice"
                                                        >
                                                            Unlink
                                                        </button>
                                                    </div>
                                                @endforeach
                                            </div>
                                            <div class="bs-invoice-linked__actions">
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('linkDocument', { transactionId: {{ $transaction->getKey() }}, documentType: 'invoice', appendInvoices: true })"
                                                    class="bs-invoice-quiet"
                                                    title="Link additional invoice(s) to this payment"
                                                >
                                                    Add invoice
                                                </button>
                                                @php($linkedSettlement = $transaction->settlementSummary())
                                                @if ((bool) $transaction->overpayment_refunded)
                                                    <button
                                                        type="button"
                                                        wire:click="clearOverpaymentRefunded({{ $transaction->getKey() }})"
                                                        class="bs-invoice-quiet"
                                                        title="Clear overpayment refunded mark"
                                                    >
                                                        Undo settled
                                                    </button>
                                                @elseif (($linkedSettlement['status'] ?? null) === 'overpaid')
                                                    <button
                                                        type="button"
                                                        wire:click="markOverpaymentRefunded({{ $transaction->getKey() }})"
                                                        wire:confirm="Mark overpayment as refunded? Invoice links will be kept and status becomes Settled."
                                                        class="bs-invoice-quiet"
                                                        title="Overpayment was refunded — keep invoice links, mark as settled"
                                                    >
                                                        Refunded
                                                    </button>
                                                @else
                                                    <button
                                                        type="button"
                                                        wire:click="markAsRefunded({{ $transaction->getKey() }})"
                                                        wire:confirm="Mark as refunded? The linked document will be unlinked and no document will be needed."
                                                        class="bs-invoice-quiet"
                                                        title="Payment was refunded — no document linking needed"
                                                    >
                                                        Refunded
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    @elseif ($transaction->payment)
                                        <div class="bs-invoice-linked">
                                            <a
                                                href="{{ $this->contractUrl($transaction->payment) }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title="{{ $transaction->payment->name }}"
                                                class="bs-invoice-link"
                                            >
                                                <x-heroicon-o-document-duplicate class="bs-invoice-link__icon" />
                                                <span>{{ $transaction->payment->name }}</span>
                                            </a>
                                            <button
                                                type="button"
                                                wire:click="unlink({{ $transaction->getKey() }})"
                                                wire:confirm="Unlink this contract from the payment?"
                                                class="bs-invoice-quiet"
                                            >
                                                Unlink
                                            </button>
                                        </div>
                                    @elseif ($transaction->employeeMonthlyPayment)
                                        @php($salaryPayment = $transaction->employeeMonthlyPayment)
                                        @php($salaryUrl = $this->salaryPaymentUrl($salaryPayment))
                                        <div class="bs-invoice-linked">
                                            @if (filled($salaryUrl))
                                                <a
                                                    href="{{ $salaryUrl }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title="{{ $this->salaryPaymentLabel($salaryPayment) }}"
                                                    class="bs-invoice-link"
                                                >
                                                    <x-heroicon-o-document-text class="bs-invoice-link__icon" />
                                                    <span>{{ $salaryPayment->employee?->fullName() ?: 'Payslip' }}</span>
                                                </a>
                                            @else
                                                <div class="bs-invoice-link" title="{{ $this->salaryPaymentLabel($salaryPayment) }}">
                                                    <x-heroicon-o-document-text class="bs-invoice-link__icon" />
                                                    <span>{{ $salaryPayment->employee?->fullName() ?: 'Payslip' }}</span>
                                                </div>
                                            @endif
                                            <button
                                                type="button"
                                                wire:click="unlink({{ $transaction->getKey() }})"
                                                wire:confirm="Unlink this salary payment from the bank line?"
                                                class="bs-invoice-quiet"
                                            >
                                                Unlink
                                            </button>
                                        </div>
                                    @elseif ($transaction->businessTripRequest)
                                        @php($trip = $transaction->businessTripRequest)
                                        @php($tripUrl = $this->businessTripUrl($trip))
                                        @php($tripSettlement = $trip->settlementSummary())
                                        @php($linkedPaymentCount = $tripSettlement['payment_count'])
                                        <div class="bs-invoice-linked">
                                            @if (filled($tripUrl))
                                                <a
                                                    href="{{ $tripUrl }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title="{{ $this->businessTripLabel($trip) }}"
                                                    class="bs-invoice-link"
                                                >
                                                    <x-heroicon-o-map class="bs-invoice-link__icon" />
                                                    <span>{{ $trip->employee?->fullName() ?: 'Business trip' }}</span>
                                                </a>
                                            @else
                                                <div class="bs-invoice-link" title="{{ $this->businessTripLabel($trip) }}">
                                                    <x-heroicon-o-map class="bs-invoice-link__icon" />
                                                    <span>{{ $trip->employee?->fullName() ?: 'Business trip' }}</span>
                                                </div>
                                            @endif
                                            <span
                                                class="bs-invoice-quiet"
                                                title="{{ $tripSettlement['detail'] ?: $tripSettlement['label'] }}"
                                            >
                                                {{ $this->formatMoney($tripSettlement['expected_total']) }}
                                                · {{ $tripSettlement['label'] }}
                                            </span>
                                            @if ($linkedPaymentCount > 1)
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('viewBusinessTripPayments', { tripId: {{ $trip->getKey() }}, transactionId: {{ $transaction->getKey() }} })"
                                                    class="bs-invoice-quiet"
                                                    title="Show all {{ $linkedPaymentCount }} bank payments linked to this trip"
                                                >
                                                    {{ $linkedPaymentCount }} payments
                                                </button>
                                            @endif
                                            <button
                                                type="button"
                                                wire:click="unlink({{ $transaction->getKey() }})"
                                                wire:confirm="Unlink this business trip from the bank line?"
                                                class="bs-invoice-quiet"
                                            >
                                                Unlink
                                            </button>
                                        </div>
                                    @elseif ($transaction->document)
                                        @php($linkedDoc = $transaction->document)
                                        @php($linkedDocUrl = $this->linkedDocumentUrl($linkedDoc))
                                        <div class="bs-invoice-linked">
                                            @if (filled($linkedDocUrl))
                                                <a
                                                    href="{{ $linkedDocUrl }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title="{{ $linkedDoc->name }}"
                                                    class="bs-invoice-link"
                                                >
                                                    <x-heroicon-o-document-text class="bs-invoice-link__icon" />
                                                    <span>{{ $linkedDoc->name }}</span>
                                                </a>
                                            @else
                                                <div class="bs-invoice-link" title="{{ $linkedDoc->name }}">
                                                    <x-heroicon-o-document-text class="bs-invoice-link__icon" />
                                                    <span>{{ $linkedDoc->name }}</span>
                                                </div>
                                            @endif
                                            <a
                                                href="{{ $this->documentEditUrl($linkedDoc) }}"
                                                class="bs-invoice-quiet"
                                                title="Edit document"
                                            >
                                                <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                            </a>
                                            <button
                                                type="button"
                                                wire:click="unlink({{ $transaction->getKey() }})"
                                                wire:confirm="Unlink this document from the bank line?"
                                                class="bs-invoice-quiet"
                                            >
                                                Unlink
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="markAsRefunded({{ $transaction->getKey() }})"
                                                wire:confirm="Mark as refunded? The linked document will be unlinked and no document will be needed."
                                                class="bs-invoice-quiet"
                                                title="Payment was refunded — no document linking needed"
                                            >
                                                Refunded
                                            </button>
                                        </div>
                                    @elseif ($transaction->doesNotNeedDocument())
                                        <div class="bs-invoice-linked">
                                            @if ($transaction->import?->primarySharepointUrl())
                                                <div
                                                    class="bs-invoice-link"
                                                    title="{{ $transaction->import->sharepoint_path ?: $transaction->import->displayName() }}"
                                                >
                                                    <x-heroicon-o-document-text class="bs-invoice-link__icon" />
                                                    <a
                                                        href="{{ $transaction->import->primarySharepointUrl() }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="text-primary-600 hover:underline dark:text-primary-400"
                                                        title="Open bank statement on SharePoint"
                                                    >Bank statement</a>
                                                </div>
                                            @endif
                                            <span
                                                class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-semibold {{ $transaction->is_refund
                                                    ? 'bg-violet-50 text-violet-800 dark:bg-violet-950/50 dark:text-violet-300'
                                                    : ($transaction->isHistorical()
                                                    ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300'
                                                    : 'bg-sky-50 text-sky-800 dark:bg-sky-950/50 dark:text-sky-300') }}"
                                                title="{{ $transaction->is_refund
                                                    ? 'Marked as refunded — no document linking needed'
                                                    : ($transaction->isHistorical()
                                                    ? 'Historical / pre–go-live payment — kept for financial data, no linking needed'
                                                    : ($transaction->isInternalTransfer()
                                                    ? 'Transfer between own accounts — auto-linked to the bank statement'
                                                    : ($transaction->isBankServiceFee()
                                                        ? 'Bank fee / service charge — auto-linked to the bank statement'
                                                        : 'Marked as no document needed'))) }}"
                                            >
                                                @if ($transaction->is_refund)
                                                    <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                                                @elseif ($transaction->isHistorical())
                                                    <x-heroicon-o-archive-box class="h-3.5 w-3.5" />
                                                @elseif ($transaction->isInternalTransfer())
                                                    <x-heroicon-o-arrows-right-left class="h-3.5 w-3.5" />
                                                @else
                                                    <x-heroicon-o-banknotes class="h-3.5 w-3.5" />
                                                @endif
                                                {{ $transaction->statementDocumentLabel() }}
                                            </span>
                                            @if ($transaction->is_refund)
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('linkDocument', { transactionId: {{ $transaction->getKey() }} })"
                                                    class="bs-invoice-btn"
                                                    title="Attach a document for this refund"
                                                >
                                                    Document
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="clearRefunded({{ $transaction->getKey() }})"
                                                    class="bs-invoice-quiet"
                                                >
                                                    Undo
                                                </button>
                                            @elseif ($transaction->isHistorical())
                                                <button
                                                    type="button"
                                                    wire:click="clearHistorical({{ $transaction->getKey() }})"
                                                    class="bs-invoice-quiet"
                                                >
                                                    Undo
                                                </button>
                                            @elseif ($transaction->no_document_needed && ! $transaction->isInternalTransfer() && ! $transaction->isBankServiceFee())
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('linkDocument', { transactionId: {{ $transaction->getKey() }} })"
                                                    class="bs-invoice-btn"
                                                    title="Attach a document instead"
                                                >
                                                    Document
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="clearNoDocumentNeeded({{ $transaction->getKey() }})"
                                                    class="bs-invoice-quiet"
                                                >
                                                    Undo
                                                </button>
                                            @endif
                                        </div>
                                    @elseif ($transaction->suggestedInvoice)
                                        @php($suggestedBatch = $transaction->relationLoaded('suggestedInvoiceBatch') ? $transaction->suggestedInvoiceBatch : collect())
                                        <div class="bs-invoice-suggest">
                                            <div class="bs-invoice-suggest__row">
                                                <div class="flex flex-col gap-1 min-w-0">
                                                    @forelse (($suggestedBatch->isNotEmpty() ? $suggestedBatch : collect([$transaction->suggestedInvoice])) as $suggestedInvoice)
                                                        <div
                                                            class="bs-invoice-link"
                                                            title="{{ $suggestedInvoice->displayNumber() }}"
                                                        >
                                                            <x-heroicon-o-document-text class="bs-invoice-link__icon" />
                                                            {!! $suggestedInvoice->openDocumentLinksHtml() !!}
                                                        </div>
                                                    @empty
                                                        <div class="bs-invoice-link">
                                                            <x-heroicon-o-document-text class="bs-invoice-link__icon" />
                                                            {!! $transaction->suggestedInvoice->openDocumentLinksHtml() !!}
                                                        </div>
                                                    @endforelse
                                                </div>
                                                <button
                                                    type="button"
                                                    wire:click="confirmSuggestedInvoice({{ $transaction->getKey() }})"
                                                    class="bs-invoice-btn bs-invoice-btn--confirm"
                                                >
                                                    Confirm
                                                </button>
                                            </div>
                                            <div
                                                class="bs-invoice-suggest__meta"
                                                title="{{ $suggestedBatch->count() > 1
                                                    ? $suggestedBatch->map->displayNumber()->implode(', ')
                                                    : trim(implode(' · ', array_filter([
                                                        optional($transaction->suggestedInvoice->invoice_date)->format('Y-m-d'),
                                                        $this->formatMoney((float) $transaction->suggestedInvoice->sum_inc_vat),
                                                        $transaction->suggestedInvoice->contact?->company_name,
                                                    ]))) }}"
                                            >
                                                @if ($suggestedBatch->count() > 1)
                                                    {{ $suggestedBatch->count() }} invoices
                                                    · {{ $suggestedBatch->map->displayNumber()->implode(', ') }}
                                                    · {{ $this->formatMoney((float) $suggestedBatch->sum('sum_inc_vat')) }}
                                                @else
                                                    Invoice
                                                    · {{ optional($transaction->suggestedInvoice->invoice_date)->format('Y-m-d') }}
                                                    · {{ $this->formatMoney((float) $transaction->suggestedInvoice->sum_inc_vat) }}
                                                @endif
                                            </div>
                                            <div class="bs-invoice-suggest__alts">
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('linkDocument', { transactionId: {{ $transaction->getKey() }}, documentType: 'invoice' })"
                                                    class="bs-invoice-quiet"
                                                >
                                                    Other document
                                                </button>
                                                <span class="bs-invoice-suggest__dot">·</span>
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('createInvoice', { transactionId: {{ $transaction->getKey() }} })"
                                                    class="bs-invoice-quiet"
                                                >
                                                    New
                                                </button>
                                                <span class="bs-invoice-suggest__dot">·</span>
                                                <button
                                                    type="button"
                                                    wire:click="markHistorical({{ $transaction->getKey() }})"
                                                    wire:confirm="Mark as historical data? The line stays on the statement but will not need invoice linking."
                                                    class="bs-invoice-quiet"
                                                    title="Pre–go-live payment — keep for financial data, skip linking"
                                                >
                                                    Historical
                                                </button>
                                                <span class="bs-invoice-suggest__dot">·</span>
                                                <button
                                                    type="button"
                                                    wire:click="markAsRefunded({{ $transaction->getKey() }})"
                                                    wire:confirm="Mark as refunded? Any linked document will be unlinked and no document will be needed."
                                                    class="bs-invoice-quiet"
                                                    title="Payment was refunded — no document linking needed"
                                                >
                                                    Refunded
                                                </button>
                                            </div>
                                        </div>
                                    @elseif ($transaction->suggestedPayment)
                                        <div class="bs-invoice-suggest">
                                            <div class="bs-invoice-suggest__row">
                                                <a
                                                    href="{{ $this->contractUrl($transaction->suggestedPayment) }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="bs-invoice-link"
                                                    title="{{ $transaction->suggestedPayment->name }}"
                                                >
                                                    <x-heroicon-o-document-duplicate class="bs-invoice-link__icon" />
                                                    <span>{{ $transaction->suggestedPayment->name }}</span>
                                                </a>
                                                <button
                                                    type="button"
                                                    wire:click="confirmSuggestedPayment({{ $transaction->getKey() }})"
                                                    class="bs-invoice-btn bs-invoice-btn--confirm"
                                                >
                                                    Confirm
                                                </button>
                                            </div>
                                            <div
                                                class="bs-invoice-suggest__meta"
                                                title="{{ trim(implode(' · ', array_filter([
                                                    $transaction->suggestedPayment->isPeriodical() ? $transaction->suggestedPayment->frequency?->label() : optional($transaction->suggestedPayment->due_date)->format('Y-m-d'),
                                                    $this->formatMoney((float) $transaction->suggestedPayment->amount),
                                                    $transaction->suggestedPayment->contact?->company_name,
                                                ]))) }}"
                                            >
                                                Contract
                                                · {{ $this->formatMoney((float) $transaction->suggestedPayment->amount) }}
                                            </div>
                                            <div class="bs-invoice-suggest__alts">
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('linkDocument', { transactionId: {{ $transaction->getKey() }}, documentType: 'contract' })"
                                                    class="bs-invoice-quiet"
                                                >
                                                    Other document
                                                </button>
                                                <span class="bs-invoice-suggest__dot">·</span>
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('createInvoice', { transactionId: {{ $transaction->getKey() }} })"
                                                    class="bs-invoice-quiet"
                                                >
                                                    New
                                                </button>
                                            </div>
                                        </div>
                                    @elseif ($transaction->suggestedSalaryPayment)
                                        <div class="bs-invoice-suggest">
                                            <div class="bs-invoice-suggest__row">
                                                @php($salaryUrl = $this->salaryPaymentUrl($transaction->suggestedSalaryPayment))
                                                @if (filled($salaryUrl))
                                                    <a
                                                        href="{{ $salaryUrl }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="bs-invoice-link"
                                                        title="{{ $this->salaryPaymentLabel($transaction->suggestedSalaryPayment) }}"
                                                    >
                                                        <x-heroicon-o-document-text class="bs-invoice-link__icon" />
                                                        <span>{{ $transaction->suggestedSalaryPayment->employee?->fullName() ?: 'Payslip' }}</span>
                                                    </a>
                                                @else
                                                    <div
                                                        class="bs-invoice-link"
                                                        title="{{ $this->salaryPaymentLabel($transaction->suggestedSalaryPayment) }}"
                                                    >
                                                        <x-heroicon-o-document-text class="bs-invoice-link__icon" />
                                                        <span>{{ $transaction->suggestedSalaryPayment->employee?->fullName() ?: 'Payslip' }}</span>
                                                    </div>
                                                @endif
                                                <button
                                                    type="button"
                                                    wire:click="confirmSuggestedSalaryPayment({{ $transaction->getKey() }})"
                                                    class="bs-invoice-btn bs-invoice-btn--confirm"
                                                >
                                                    Confirm
                                                </button>
                                            </div>
                                            <div
                                                class="bs-invoice-suggest__meta"
                                                title="{{ $this->salaryPaymentLabel($transaction->suggestedSalaryPayment) }}"
                                            >
                                                Payslip
                                                · {{ optional($transaction->suggestedSalaryPayment->payment_date)->format('Y-m-d') }}
                                                · {{ $this->formatMoney((float) ($transaction->suggestedSalaryPayment->net_amount ?? 0)) }}
                                            </div>
                                            <div class="bs-invoice-suggest__alts">
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('linkDocument', { transactionId: {{ $transaction->getKey() }}, documentType: 'payslip' })"
                                                    class="bs-invoice-quiet"
                                                >
                                                    Other document
                                                </button>
                                            </div>
                                        </div>
                                    @elseif ($transaction->suggestedBusinessTrip)
                                        <div class="bs-invoice-suggest">
                                            <div class="bs-invoice-suggest__row">
                                                @php($tripUrl = $this->businessTripUrl($transaction->suggestedBusinessTrip))
                                                @if (filled($tripUrl))
                                                    <a
                                                        href="{{ $tripUrl }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="bs-invoice-link"
                                                        title="{{ $this->businessTripLabel($transaction->suggestedBusinessTrip) }}"
                                                    >
                                                        <x-heroicon-o-map class="bs-invoice-link__icon" />
                                                        <span>{{ $transaction->suggestedBusinessTrip->employee?->fullName() ?: 'Business trip' }}</span>
                                                    </a>
                                                @else
                                                    <div
                                                        class="bs-invoice-link"
                                                        title="{{ $this->businessTripLabel($transaction->suggestedBusinessTrip) }}"
                                                    >
                                                        <x-heroicon-o-map class="bs-invoice-link__icon" />
                                                        <span>{{ $transaction->suggestedBusinessTrip->employee?->fullName() ?: 'Business trip' }}</span>
                                                    </div>
                                                @endif
                                                <button
                                                    type="button"
                                                    wire:click="confirmSuggestedBusinessTrip({{ $transaction->getKey() }})"
                                                    class="bs-invoice-btn bs-invoice-btn--confirm"
                                                >
                                                    Confirm
                                                </button>
                                            </div>
                                            <div
                                                class="bs-invoice-suggest__meta"
                                                title="{{ $this->businessTripLabel($transaction->suggestedBusinessTrip) }}"
                                            >
                                                @php($suggestedSettlement = $transaction->suggestedBusinessTrip->settlementSummary())
                                                Business trip
                                                · {{ $transaction->suggestedBusinessTrip->country_name }}
                                                · {{ optional($transaction->suggestedBusinessTrip->date_from)->format('Y-m-d') }}
                                                · {{ $this->formatMoney($suggestedSettlement['expected_total']) }}
                                                · {{ $suggestedSettlement['label'] }}
                                            </div>
                                            <div class="bs-invoice-suggest__alts">
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('linkDocument', { transactionId: {{ $transaction->getKey() }}, documentType: 'business_trip' })"
                                                    class="bs-invoice-quiet"
                                                >
                                                    Other document
                                                </button>
                                            </div>
                                        </div>
                                    @else
                                        <div class="bs-invoice-actions">
                                            <button
                                                type="button"
                                                wire:click="mountAction('linkDocument', { transactionId: {{ $transaction->getKey() }} })"
                                                class="bs-invoice-btn"
                                            >
                                                Document
                                            </button>
                                            @if ($transaction->isRefund())
                                                <button
                                                    type="button"
                                                    wire:click="markAsRefunded({{ $transaction->getKey() }})"
                                                    wire:confirm="Mark as refunded with no document needed?"
                                                    class="bs-invoice-btn bs-invoice-btn--confirm"
                                                    title="Refund — skip document linking"
                                                >
                                                    No document needed
                                                </button>
                                            @else
                                                <button
                                                    type="button"
                                                    wire:click="mountAction('createInvoice', { transactionId: {{ $transaction->getKey() }} })"
                                                    class="bs-invoice-btn bs-invoice-btn--add"
                                                >
                                                    <x-heroicon-o-plus class="bs-invoice-btn__icon" />
                                                    Add invoice
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="markHistorical({{ $transaction->getKey() }})"
                                                    wire:confirm="Mark as historical data? The line stays on the statement but will not need invoice linking."
                                                    class="bs-invoice-quiet"
                                                    title="Pre–go-live payment (e.g. March) — keep for financial data, skip linking"
                                                >
                                                    Historical
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="markNoDocumentNeeded({{ $transaction->getKey() }})"
                                                    wire:confirm="Mark as no document needed?"
                                                    class="bs-invoice-quiet"
                                                    title="Skip document linking for this line"
                                                >
                                                    No document needed
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="markAsRefunded({{ $transaction->getKey() }})"
                                                    wire:confirm="Mark as refunded? Any linked document will be unlinked and no document will be needed."
                                                    class="bs-invoice-quiet"
                                                    title="Payment was refunded — no document linking needed"
                                                >
                                                    Refunded
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                    </div>
                                    @php($comment = $transaction->displayComment())
                                    <div
                                        wire:key="bs-comment-{{ $transaction->getKey() }}"
                                        class="bs-inline-edit bs-linked-stack__comment"
                                        x-data="{
                                            editing: false,
                                            saving: false,
                                            value: @js($comment),
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
                                                    await $wire.updateComment({{ $transaction->getKey() }}, next === '' ? null : next);
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
                                            class="bs-inline-edit__trigger"
                                            title="{{ filled($comment) ? 'Edit comment' : 'Add comment' }}"
                                        >
                                            <span x-show="value" x-text="value" class="bs-inline-edit__text bs-clamp-2"></span>
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
                                    </div>
                                </td>
                                <td class="bs-td bs-td--category">
                                    @include('filament.admin.pages.partials.invoice-category-select', [
                                        'invoice' => $transaction->categoryInvoice(),
                                        'categoryGroups' => $categoryGroups,
                                        'wireKey' => 'bs-invoice-category-'.$transaction->getKey(),
                                    ])
                                </td>
                                <td class="bs-td bs-td--status">
                                    @php($settlement = $transaction->settlementSummary())
                                    <div @class([
                                        'bs-payment-status',
                                        'bs-payment-status--paid' => $settlement['status'] === 'paid',
                                        'bs-payment-status--partly' => $settlement['status'] === 'partly_paid',
                                        'bs-payment-status--overpaid' => $settlement['status'] === 'overpaid',
                                        'bs-payment-status--settled' => $settlement['status'] === 'settled',
                                        'bs-payment-status--underpaid' => $settlement['status'] === 'underpaid',
                                        'bs-payment-status--unpaid' => $settlement['status'] === 'unpaid',
                                        'bs-payment-status--refund' => $settlement['status'] === 'refund',
                                        'bs-payment-status--refunded' => $settlement['status'] === 'refunded',
                                    ])>
                                        <span class="bs-payment-status__label">{{ $settlement['label'] }}</span>
                                        @if (filled($settlement['detail']))
                                            <span class="bs-payment-status__detail" title="{{ $settlement['detail'] }}">{{ $settlement['detail'] }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="bs-td bs-td--amount">
                                    <div class="bs-direction-cell">
                                        @if ($transaction->isInternalTransfer())
                                            @if ($transaction->direction?->isIncome())
                                                <span class="bs-direction-amount text-gray-400 dark:text-gray-500" title="Own-account transfer (not income)">
                                                    {{ $this->formatMoney((float) $transaction->amount) }}
                                                </span>
                                            @endif
                                        @elseif ($transaction->incomeAmount() > 0)
                                            <span class="bs-direction-amount bs-direction-amount--income" title="Income">
                                                +{{ $this->formatMoney($transaction->incomeAmount()) }}
                                            </span>
                                        @else
                                            <button
                                                type="button"
                                                class="bs-direction-flip"
                                                title="Mark as income"
                                                wire:click="updateDirection({{ $transaction->getKey() }}, 'credit')"
                                                wire:confirm="Move this line to Income?"
                                            >
                                                → In
                                            </button>
                                        @endif
                                    </div>
                                </td>
                                <td class="bs-td bs-td--amount">
                                    <div class="bs-direction-cell">
                                        @if ($transaction->isInternalTransfer())
                                            @if (! $transaction->direction?->isIncome())
                                                <span class="bs-direction-amount text-gray-400 dark:text-gray-500" title="Own-account transfer (not expense)">
                                                    {{ $this->formatMoney((float) $transaction->amount) }}
                                                </span>
                                            @endif
                                        @elseif ($transaction->outcomeAmount() > 0)
                                            <span class="bs-direction-amount bs-direction-amount--outcome" title="Outcome">
                                                −{{ $this->formatMoney($transaction->outcomeAmount()) }}
                                            </span>
                                        @else
                                            <button
                                                type="button"
                                                class="bs-direction-flip"
                                                title="Mark as outcome"
                                                wire:click="updateDirection({{ $transaction->getKey() }}, 'debit')"
                                                wire:confirm="Move this line to Outcome?"
                                            >
                                                → Out
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            {{ $this->hasActiveFilters() ? 'No payments match the filters.' : 'No bank statements uploaded yet.' }}
        </div>
    @endforelse
    </div>
</x-filament-panels::page>
