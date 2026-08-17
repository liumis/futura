<x-filament-panels::page>
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <form wire:submit="create" class="space-y-4">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit" size="lg">
                    Upload
                </x-filament::button>
            </div>
        </form>
    </div>

    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        {{ $this->filtersForm }}
    </div>

    <div class="invoice-table-wrap overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <table class="invoice-table min-w-full table-auto text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Company</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Invoice no</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Order</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Invoice date</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-200">Sum without VAT</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-200">VAT</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-200">Sum inc. VAT</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Income type</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Expense type</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Upload date</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Uploaded user</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-900">
                @forelse ($this->getInvoices() as $invoice)
                    @php($hasFinanceWarning = $invoice->hasFinanceDetailsWarning())
                    <tr @class([
                        'bg-danger-50 dark:bg-danger-950/40' => $hasFinanceWarning,
                    ])>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                            <div class="flex items-start gap-2">
                                @if ($hasFinanceWarning)
                                    <span
                                        class="mt-0.5 inline-flex shrink-0 text-danger-600 dark:text-danger-400"
                                        title="{{ $invoice->financeDetailsWarningMessage() }}"
                                    >
                                        <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
                                    </span>
                                @endif
                                <span>{{ $invoice->contact?->company_name ?? '—' }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                            @if (filled($invoice->invoice_number))
                                <button
                                    type="button"
                                    wire:click="mountAction('invoiceDetails', { invoiceId: {{ $invoice->getKey() }} })"
                                    class="text-left text-primary-600 hover:underline dark:text-primary-400"
                                    title="Details"
                                >
                                    {{ $invoice->invoice_number }}
                                </button>
                            @else
                                <button
                                    type="button"
                                    wire:click="mountAction('invoiceDetails', { invoiceId: {{ $invoice->getKey() }} })"
                                    class="text-left text-primary-600 hover:underline dark:text-primary-400"
                                    title="Details"
                                >
                                    —
                                </button>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                            @if ($invoice->order_id)
                                <a href="{{ \App\Filament\Admin\Resources\Orders\OrderResource::getUrl('edit', ['record' => $invoice->order_id]) }}" class="text-primary-600 hover:underline dark:text-primary-400">
                                    #{{ $invoice->order_id }}
                                </a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                            {{ optional($invoice->invoice_date)->format('Y-m-d') }}
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-200">
                            {{ \App\Support\Money::format($invoice->sum_without_vat) }}
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-200">
                            {{ \App\Support\Money::format($invoice->vat) }}
                        </td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-200">
                            {{ \App\Support\Money::format($invoice->sum_inc_vat) }}
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                            {{ $invoice->incomeType?->name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                            {{ $invoice->expenseType?->name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                            {{ optional($invoice->upload_date)->format('Y-m-d') }}
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                            {{ $invoice->uploadedUser?->name ?? $invoice->uploadedUser?->email ?? '—' }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <div class="flex flex-nowrap items-center gap-1">
                                @if ($invoice->order_id && $invoice->file_mime === 'application/pdf')
                                    <span class="inline-flex flex-nowrap items-center gap-0.5">
                                        <a
                                            href="{{ route('invoices.file', ['invoice' => $invoice->getKey(), 'lang' => 'lt']) }}"
                                            target="_blank"
                                            title="Open Lithuanian invoice PDF"
                                            class="inline-flex items-center rounded-md px-1.5 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                        >
                                            LT
                                        </a>
                                        <span class="text-xs text-gray-400">/</span>
                                        <a
                                            href="{{ route('invoices.file', ['invoice' => $invoice->getKey(), 'lang' => 'en']) }}"
                                            target="_blank"
                                            title="Open English invoice PDF"
                                            class="inline-flex items-center rounded-md px-1.5 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                        >
                                            EN
                                        </a>
                                    </span>
                                @elseif (filled($invoice->file_content) || filled($invoice->pdf_path))
                                    <a
                                        href="{{ route('invoices.file', ['invoice' => $invoice->getKey()]) }}"
                                        target="_blank"
                                        title="{{ $invoice->file_mime === 'application/pdf' ? 'Open PDF' : 'Open file' }}"
                                        class="inline-flex items-center rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-primary-600 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-primary-400"
                                    >
                                        <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                                    </a>
                                @endif

                                <button
                                    type="button"
                                    wire:click="mountAction('invoiceDetails', { invoiceId: {{ $invoice->getKey() }} })"
                                    title="Details"
                                    class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-primary-600 transition hover:bg-gray-100 dark:text-primary-400 dark:hover:bg-gray-800"
                                >
                                    Details
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
                @empty
                    <tr>
                        <td colspan="12" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                            @if ($this->hasActiveInvoiceFilters())
                                No invoices match your filters.
                            @else
                                No invoices uploaded yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
