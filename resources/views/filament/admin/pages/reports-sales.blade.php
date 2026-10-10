<x-filament-panels::page>
    @php($report = $this->report())

    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Filter by order date and switch between total sum (EUR, without VAT) and meters.</p>
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
        'description' => $report['period_label'].' · '.$report['metric_label'],
        'footer' => $report['customers'].' customer(s) · '.$report['orders'].' order(s). Draft, pending, and rejected orders are excluded.',
        'stats' => $report['customers'] === 0 ? [] : [
            ['label' => 'Customers', 'value' => number_format($report['customers'])],
            ['label' => 'Orders', 'value' => number_format($report['orders'])],
            [
                'label' => $report['metric'] === \App\Services\SalesReportBuilder::METRIC_METERS ? 'Total meters' : 'Grand total',
                'value' => \App\Services\SalesReportBuilder::formatValue($report['grand_total'], $report['metric']),
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])

    <div class="invoice-table-wrap mt-6 overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <table class="invoice-table sales-report-table min-w-full table-auto text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Company</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Customer code</th>
                    @foreach ($report['collections'] as $collection)
                        <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-200">{{ $collection['label'] }}</th>
                    @endforeach
                    <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-200">Total</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-900">
                @forelse ($report['rows'] as $row)
                    <tr>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['company'] }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['code'] }}</td>
                        @foreach ($report['collections'] as $collection)
                            <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-200">
                                {{ \App\Services\SalesReportBuilder::formatValue($row['values'][$collection['key']] ?? 0, $report['metric']) }}
                            </td>
                        @endforeach
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-gray-900 dark:text-gray-100">
                            {{ \App\Services\SalesReportBuilder::formatValue($row['total'], $report['metric']) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 3 + count($report['collections']) }}" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                            No sales in this period.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if ($report['rows'] !== [])
                <tfoot class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-900 dark:text-white" colspan="2">Total</th>
                        @foreach ($report['collections'] as $collection)
                            <th class="px-4 py-3 text-right tabular-nums font-semibold text-gray-900 dark:text-white">
                                {{ \App\Services\SalesReportBuilder::formatValue($report['column_totals'][$collection['key']] ?? 0, $report['metric']) }}
                            </th>
                        @endforeach
                        <th class="px-4 py-3 text-right tabular-nums font-semibold text-gray-900 dark:text-white">
                            {{ \App\Services\SalesReportBuilder::formatValue($report['grand_total'], $report['metric']) }}
                        </th>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</x-filament-panels::page>
