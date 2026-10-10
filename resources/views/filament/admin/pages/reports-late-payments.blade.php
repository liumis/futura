<x-filament-panels::page>
    @php($report = $this->report())

    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Unpaid invoices and contract payments. Rows past their due date are marked Late.</p>
            </div>
            <div class="fi-report-filters__actions shrink-0 flex flex-wrap items-center justify-end gap-2">
                {{ $this->exportCsvAction }}
                {{ $this->exportExcelAction }}
            </div>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @include('filament.admin.components.report-stat-summary', [
        'title' => $report['title'],
        'description' => $report['period_label'].' · '.$report['source_label'].' · '.$report['status_label'],
        'footer' => $report['count'] === 0
            ? null
            : $report['invoice_count'].' invoice(s) · '.$report['contract_count'].' contract payment(s).',
        'stats' => $report['count'] === 0 ? [] : [
            ['label' => 'Items', 'value' => number_format($report['count'])],
            ['label' => 'Late', 'value' => number_format($report['late_count'])],
            ['label' => 'Late total', 'value' => \App\Services\LatePaymentsReportBuilder::money($report['late_total'])],
            [
                'label' => 'Total',
                'value' => \App\Services\LatePaymentsReportBuilder::money($report['grand_total']),
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])

    <div class="invoice-table-wrap mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <table class="invoice-table late-payments-table min-w-full table-auto text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Number</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Date</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Company</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Due date</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-200">Sum</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200"></th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-900">
                @forelse ($report['rows'] as $row)
                    <tr @class([
                        'even:bg-gray-50 dark:even:bg-gray-800/40',
                    ])>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                            @php($url = $this->rowUrl($row))
                            <div class="flex flex-col gap-0.5">
                                @if (filled($url))
                                    <a href="{{ $url }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $row['number'] }}
                                    </a>
                                @else
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $row['number'] }}</span>
                                @endif
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['source_label'] }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-200">
                            {{ \App\Services\LatePaymentsReportBuilder::formatDate($row['date']) }}
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['company'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-200">
                            {{ \App\Services\LatePaymentsReportBuilder::formatDate($row['due_date']) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-gray-900 dark:text-gray-100">
                            {{ \App\Services\LatePaymentsReportBuilder::money($row['amount']) }}
                        </td>
                        <td class="px-4 py-3">
                            @if ($row['late'])
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide bg-red-600 text-white">Late</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                            No unpaid invoices or contract payments in this period.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if ($report['rows'] !== [])
                <tfoot class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-900 dark:text-white" colspan="4">Total</th>
                        <th class="px-4 py-3 text-right tabular-nums font-semibold text-gray-900 dark:text-white">
                            {{ \App\Services\LatePaymentsReportBuilder::money($report['grand_total']) }}
                        </th>
                        <th></th>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</x-filament-panels::page>
