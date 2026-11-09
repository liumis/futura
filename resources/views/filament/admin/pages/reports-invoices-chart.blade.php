<x-filament-panels::page>
    @php
        $report = $this->report();
    @endphp

    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Filters</h2>
            <p class="fi-report-filters__description">
                Invoice totals (EUR) by payment status. Group by invoice date or payment due date (invoice date + term) to plan when cash goes out.
            </p>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @include('filament.admin.components.report-stat-summary', [
        'title' => $report['title'],
        'description' => $report['period_label'].' · '.$report['date_basis_label'].' · '.$report['status_label'].' · '.$report['late_days_label'].' · '.$report['contact_label'].' · '.$report['country_label'],
        'footer' => $report['invoice_count'].' invoice(s) across '.count($report['days']).' day(s). Outstanding still due: '.\App\Services\InvoicesChartReportBuilder::formatEuro($report['outstanding_total']).(($report['date_basis'] ?? '') === \App\Services\InvoicesChartReportBuilder::DATE_BASIS_DUE ? ' · To pay in period: '.\App\Services\InvoicesChartReportBuilder::formatEuro($report['due_to_pay_total'] ?? 0) : '').'.',
        'stats' => $report['invoice_count'] === 0 ? [] : array_values(array_filter([
            ['label' => 'Paid', 'value' => \App\Services\InvoicesChartReportBuilder::formatEuro($report['paid_total']).' ('.$report['paid_count'].')'],
            ['label' => 'Partly paid', 'value' => \App\Services\InvoicesChartReportBuilder::formatEuro($report['partly_paid_total']).' ('.$report['partly_paid_count'].')'],
            ['label' => 'Unpaid', 'value' => \App\Services\InvoicesChartReportBuilder::formatEuro($report['unpaid_total']).' ('.$report['unpaid_count'].')'],
            ($report['date_basis'] ?? '') === \App\Services\InvoicesChartReportBuilder::DATE_BASIS_DUE
                ? ['label' => 'Still to pay', 'value' => \App\Services\InvoicesChartReportBuilder::formatEuro($report['due_to_pay_total'] ?? 0), 'emphasis' => true]
                : [
                    'label' => 'Total',
                    'value' => \App\Services\InvoicesChartReportBuilder::formatEuro($report['grand_total']),
                    'emphasis' => true,
                ],
        ])),
        'class' => 'mt-6',
    ])

    <div class="mt-6" id="invoices-chart-root" data-has-invoices="{{ $report['invoice_count'] > 0 ? '1' : '0' }}">
        <script type="application/json" id="invoices-chart-json">@json($report)</script>

        @if ($report['invoice_count'] === 0)
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                No invoices for these filters.
            </div>
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-gray-950/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/40">
                        <h3 class="text-sm font-semibold tracking-tight text-gray-950 dark:text-white">
                            Payment status
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ \App\Services\InvoicesChartReportBuilder::formatEuro($report['grand_total']) }}
                        </p>
                    </div>
                    <div class="p-4">
                        <div class="relative mx-auto h-80 w-full max-w-md">
                            <canvas id="invoices-status-chart-canvas"></canvas>
                        </div>
                    </div>
                </section>

                <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-gray-950/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/40">
                        <h3 class="text-sm font-semibold tracking-tight text-gray-950 dark:text-white">
                            {{ ($report['date_basis'] ?? '') === \App\Services\InvoicesChartReportBuilder::DATE_BASIS_DUE ? 'By payment due day' : 'By invoice day' }}
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ ($report['date_basis'] ?? '') === \App\Services\InvoicesChartReportBuilder::DATE_BASIS_DUE ? 'Cash to pay (€)' : 'Stacked totals (€)' }}
                        </p>
                    </div>
                    <div class="p-4">
                        <div class="relative h-80 w-full">
                            <canvas id="invoices-day-chart-canvas"></canvas>
                        </div>
                    </div>
                </section>
            </div>
        @endif
    </div>

    <div class="invoice-table-wrap mt-6 overflow-x-auto border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/40">
            <div>
                <p class="text-[10px] font-medium uppercase tracking-[0.14em] text-gray-500">Payments</p>
                <p class="text-sm text-gray-700 dark:text-gray-200">
                    @if ($this->selectedPayableCount() >= 1)
                        {{ $this->selectedPayableCount() }} selected · edit Pay now for partial SEPA
                    @else
                        Tick unpaid invoices, then Generate SEPA
                    @endif
                </p>
            </div>
            <div>
                {{ $this->makeBankPaymentAction }}
            </div>
        </div>

        <table class="invoice-table invoices-chart-table min-w-full table-auto text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="w-10 px-3 py-3 text-left">
                        <input
                            type="checkbox"
                            class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                            wire:click="toggleSelectAllPayable"
                            {{ $this->selectAllPayable && count($this->payableRows()) >= 1 ? 'checked' : '' }}
                            {{ count($this->payableRows()) === 0 ? 'disabled' : '' }}
                            title="Select all unpaid"
                        />
                    </th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Number</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Invoice date</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Company</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Due date</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200">Status</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-200">Sum</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-200">Still to pay</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700 dark:text-gray-200 whitespace-nowrap">Pay now</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-700 dark:text-gray-200"></th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-900">
                @forelse (($report['rows'] ?? []) as $row)
                    @php
                        $rowId = (int) ($row['id'] ?? 0);
                        $outstanding = round((float) ($row['outstanding'] ?? 0), 2);
                        $payable = $outstanding >= 0.01;
                        $selected = in_array($rowId, array_map('intval', $this->selectedInvoiceIds), true);
                        $url = $this->rowUrl($row);
                    @endphp
                    <tr @class([
                        'even:bg-gray-50 dark:even:bg-gray-800/40',
                        'bg-warning-50/40 dark:bg-warning-950/20' => $selected,
                    ])>
                        <td class="px-3 py-3">
                            @if ($payable)
                                <input
                                    type="checkbox"
                                    value="{{ $rowId }}"
                                    wire:model.live="selectedInvoiceIds"
                                    class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                                />
                            @else
                                <span class="inline-block w-4"></span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                            @if (filled($url))
                                <a href="{{ $url }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $row['number'] }}
                                </a>
                            @else
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $row['number'] }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-200">
                            {{ \App\Services\InvoicesChartReportBuilder::formatDate($row['invoice_date'] ?? null) }}
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['company'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-200">
                            {{ \App\Services\InvoicesChartReportBuilder::formatDate($row['due_date'] ?? null) }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-200">
                            {{ $row['status_label'] }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-gray-900 dark:text-gray-100">
                            {{ \App\Services\InvoicesChartReportBuilder::formatEuro((float) ($row['amount'] ?? 0)) }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-gray-900 dark:text-gray-100">
                            {{ \App\Services\InvoicesChartReportBuilder::formatEuro($outstanding) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($payable)
                                <input
                                    type="number"
                                    min="0.01"
                                    max="{{ $outstanding }}"
                                    step="0.01"
                                    wire:model.blur="paymentAmounts.{{ $rowId }}"
                                    {{ $selected ? '' : 'disabled' }}
                                    class="w-28 border border-gray-300 bg-white px-2 py-1 text-right tabular-nums text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 disabled:bg-gray-50 disabled:text-gray-400 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                    title="Payment amount for SEPA (max {{ number_format($outstanding, 2, '.', '') }})"
                                />
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if (! empty($row['late']))
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide bg-red-600 text-white">Late</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                            No invoices for these filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if (($report['rows'] ?? []) !== [])
                <tfoot class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-900 dark:text-white" colspan="6">Total</th>
                        <th class="px-4 py-3 text-right tabular-nums font-semibold text-gray-900 dark:text-white">
                            {{ \App\Services\InvoicesChartReportBuilder::formatEuro((float) $report['grand_total']) }}
                        </th>
                        <th class="px-4 py-3 text-right tabular-nums font-semibold text-gray-900 dark:text-white">
                            {{ \App\Services\InvoicesChartReportBuilder::formatEuro((float) ($report['due_to_pay_total'] ?? $report['outstanding_total'])) }}
                        </th>
                        <th></th>
                        <th></th>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @assets
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" data-navigate-track></script>
    @endassets

    @script
    <script>
        let invoicesStatusChart = null;
        let invoicesDayChart = null;

        const formatInvoicesEuro = (amount) => {
            const value = Number(amount ?? 0);
            if (! Number.isFinite(value)) {
                return '—';
            }

            return new Intl.NumberFormat(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(value) + ' €';
        };

        const destroyInvoicesCharts = () => {
            if (invoicesStatusChart) {
                try { invoicesStatusChart.destroy(); } catch (e) {}
                invoicesStatusChart = null;
            }
            if (invoicesDayChart) {
                try { invoicesDayChart.destroy(); } catch (e) {}
                invoicesDayChart = null;
            }
        };

        const renderInvoicesCharts = () => {
            const root = document.getElementById('invoices-chart-root');
            const statusCanvas = document.getElementById('invoices-status-chart-canvas');
            const dayCanvas = document.getElementById('invoices-day-chart-canvas');
            const jsonEl = document.getElementById('invoices-chart-json');

            if (! root || root.dataset.hasInvoices !== '1' || ! jsonEl) {
                destroyInvoicesCharts();
                return;
            }

            if (typeof window.Chart === 'undefined') {
                setTimeout(renderInvoicesCharts, 100);
                return;
            }

            let report = {};
            try {
                report = JSON.parse(jsonEl.textContent || '{}');
            } catch (error) {
                console.error(error);
                return;
            }

            destroyInvoicesCharts();

            if (statusCanvas) {
                invoicesStatusChart = new Chart(statusCanvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Paid', 'Partly paid', 'Unpaid'],
                        datasets: [{
                            data: [
                                Number(report.paid_total ?? 0),
                                Number(report.partly_paid_total ?? 0),
                                Number(report.unpaid_total ?? 0),
                            ],
                            // Brandbook: ink #222222, signal #EB8027, line #C9C9C9 (no green)
                            backgroundColor: [
                                'rgba(34, 34, 34, 0.9)',
                                'rgba(235, 128, 39, 0.9)',
                                'rgba(201, 201, 201, 0.95)',
                            ],
                            borderColor: [
                                'rgba(34, 34, 34, 1)',
                                'rgba(235, 128, 39, 1)',
                                'rgba(201, 201, 201, 1)',
                            ],
                            borderWidth: 1,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label: (context) => {
                                        const label = context.label || '';
                                        return label + ': ' + formatInvoicesEuro(Number(context.parsed ?? 0));
                                    },
                                },
                            },
                        },
                    },
                });
            }

            const days = Array.isArray(report.days) ? report.days : [];
            const byDueDate = (report.date_basis || '') === 'due_date';
            if (dayCanvas && days.length > 0) {
                invoicesDayChart = new Chart(dayCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: days.map((day) => day.label ?? day.day),
                        datasets: byDueDate
                            ? [
                                {
                                    label: 'Still to pay',
                                    data: days.map((day) => Number(day.due_to_pay ?? 0)),
                                    backgroundColor: 'rgba(235, 128, 39, 0.9)',
                                    borderColor: 'rgba(235, 128, 39, 1)',
                                    borderWidth: 1,
                                },
                                {
                                    label: 'Already paid (due that day)',
                                    data: days.map((day) => Number(day.paid ?? 0)),
                                    backgroundColor: 'rgba(34, 34, 34, 0.55)',
                                    borderColor: 'rgba(34, 34, 34, 1)',
                                    borderWidth: 1,
                                },
                            ]
                            : [
                                {
                                    label: 'Paid',
                                    data: days.map((day) => Number(day.paid ?? 0)),
                                    backgroundColor: 'rgba(34, 34, 34, 0.9)',
                                    borderColor: 'rgba(34, 34, 34, 1)',
                                    borderWidth: 1,
                                },
                                {
                                    label: 'Partly paid',
                                    data: days.map((day) => Number(day.partly_paid ?? 0)),
                                    backgroundColor: 'rgba(235, 128, 39, 0.9)',
                                    borderColor: 'rgba(235, 128, 39, 1)',
                                    borderWidth: 1,
                                },
                                {
                                    label: 'Unpaid',
                                    data: days.map((day) => Number(day.unpaid ?? 0)),
                                    backgroundColor: 'rgba(201, 201, 201, 0.95)',
                                    borderColor: 'rgba(201, 201, 201, 1)',
                                    borderWidth: 1,
                                },
                            ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                stacked: true,
                                title: {
                                    display: true,
                                    text: byDueDate ? 'Payment due date' : 'Invoice date',
                                },
                            },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                title: { display: true, text: 'Sum (€)' },
                            },
                        },
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label: (context) => {
                                        const label = context.dataset.label || '';
                                        return label + ': ' + formatInvoicesEuro(Number(context.parsed.y ?? 0));
                                    },
                                },
                            },
                        },
                    },
                });
            }
        };

        renderInvoicesCharts();

        Livewire.hook('morph.updated', () => {
            requestAnimationFrame(() => renderInvoicesCharts());
        });
    </script>
    @endscript
</x-filament-panels::page>
