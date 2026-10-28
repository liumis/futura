<x-filament-panels::page>
    @php($report = $this->report())

    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Filters</h2>
            <p class="fi-report-filters__description">
                Invoice totals (EUR) by payment status. Filter by date range, late days, counterpart, and country.
            </p>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @include('filament.admin.components.report-stat-summary', [
        'title' => $report['title'],
        'description' => $report['period_label'].' · '.$report['status_label'].' · '.$report['late_days_label'].' · '.$report['contact_label'].' · '.$report['country_label'],
        'footer' => $report['invoice_count'].' invoice(s) across '.count($report['days']).' day(s). Outstanding still due: '.\App\Services\InvoicesChartReportBuilder::formatEuro($report['outstanding_total']).'.',
        'stats' => $report['invoice_count'] === 0 ? [] : [
            ['label' => 'Paid', 'value' => \App\Services\InvoicesChartReportBuilder::formatEuro($report['paid_total']).' ('.$report['paid_count'].')'],
            ['label' => 'Partly paid', 'value' => \App\Services\InvoicesChartReportBuilder::formatEuro($report['partly_paid_total']).' ('.$report['partly_paid_count'].')'],
            ['label' => 'Unpaid', 'value' => \App\Services\InvoicesChartReportBuilder::formatEuro($report['unpaid_total']).' ('.$report['unpaid_count'].')'],
            [
                'label' => 'Total',
                'value' => \App\Services\InvoicesChartReportBuilder::formatEuro($report['grand_total']),
                'emphasis' => true,
            ],
        ],
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
                            By day
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Stacked totals (€)
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
            if (dayCanvas && days.length > 0) {
                invoicesDayChart = new Chart(dayCanvas.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: days.map((day) => day.label ?? day.day),
                        datasets: [
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
                            x: { stacked: true, title: { display: true, text: 'Days' } },
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
