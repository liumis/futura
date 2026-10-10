<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Metinė A ir B klasės išmokų deklaracija (GPM312) — dividend payments (code 26) and private-provider invoices (code 53) for the selected year.</p>
            </div>
            <div class="fi-report-filters__actions shrink-0">
                {{ $this->generateReportAction }}
            </div>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @php
        $report = $this->report();
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'GPM312 totals',
        'description' => $report['period_label'].' · '.$report['company_name'].(filled($report['company_id']) ? ' · įm. k. '.$report['company_id'] : ''),
        'footer' => $report['dividend_count'].' dividend payment(s) · '.$report['invoice_count'].' private-provider invoice(s) · '.$report['line_count'].' GPM312L line(s). Cancelled payments are excluded.',
        'stats' => [
            ['label' => '4 · Gross (L8)', 'value' => $report['field4_label'], 'hint' => 'Dividends and private-provider invoices'],
            ['label' => '5 · Withheld GPM (L10)', 'value' => $report['field5_label'], 'hint' => 'From dividend payments', 'emphasis' => true],
            ['label' => '6 · GPM from own funds (L11)', 'value' => $report['field6_label'], 'hint' => 'Not used for dividends or private providers'],
            ['label' => '7 · Paid abroad (L13)', 'value' => $report['field7_label'], 'hint' => 'Not used'],
        ],
        'class' => 'mt-6',
    ])

    <div class="mt-6">
        {{ $this->table }}
    </div>

    @php
        $privateInvoices = $this->privateProviderInvoices();
    @endphp

    <section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Private provider invoices</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">GPM312L class B, income code 53. Amounts are included in field 4; GPM is not withheld.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr class="text-left text-gray-600 dark:text-gray-300">
                        <th class="px-4 py-2 font-medium">Provider</th>
                        <th class="px-4 py-2 font-medium">Invoice no</th>
                        <th class="px-4 py-2 font-medium">Invoice date</th>
                        <th class="px-4 py-2 font-medium text-right">Gross (L8)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($privateInvoices as $invoice)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-200">{{ $invoice->contact?->company_name ?: ($invoice->contact?->contact_name ?: '—') }}</td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-200">{{ $invoice->invoice_number ?: '—' }}</td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-200">{{ optional($invoice->invoice_date)->format('Y-m-d') }}</td>
                            <td class="px-4 py-2 text-right text-gray-700 dark:text-gray-200">{{ \App\Support\Money::format($invoice->sum_inc_vat) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No private-provider invoices for this year.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
