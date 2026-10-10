<x-filament-panels::page>
    @php($report = $this->report())

    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Filters</h2>
            <p class="fi-report-filters__description">
                Bank statement transactions by booking day. Switch between sum (€) and count, and filter by direction.
            </p>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @include('filament.admin.components.report-stat-summary', [
        'title' => $report['title'],
        'description' => $report['period_label'].' · '.$report['metric_label'].' · '.$report['direction_label'],
        'footer' => $report['transactions'].' transaction(s) across '.count($report['days']).' day(s).',
        'stats' => $report['transactions'] === 0 ? [] : [
            ['label' => 'Days', 'value' => number_format(count($report['days']))],
            ['label' => 'Transactions', 'value' => number_format($report['transactions'])],
            ['label' => 'Income', 'value' => \App\Services\BankPaymentsChartReportBuilder::formatValue($report['income_total'], \App\Services\BankPaymentsChartReportBuilder::METRIC_SUM)],
            ['label' => 'Outcome', 'value' => \App\Services\BankPaymentsChartReportBuilder::formatValue($report['outcome_total'], \App\Services\BankPaymentsChartReportBuilder::METRIC_SUM)],
            [
                'label' => $report['metric'] === \App\Services\BankPaymentsChartReportBuilder::METRIC_COUNT ? 'Total count' : 'Total sum',
                'value' => \App\Services\BankPaymentsChartReportBuilder::formatValue($report['grand_total'], $report['metric']),
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])

    <div class="mt-6" id="bank-payments-chart-root" data-has-transactions="{{ $report['transactions'] > 0 ? '1' : '0' }}">
        <script type="application/json" id="bank-payments-chart-json">@json($report)</script>

        @if ($report['transactions'] === 0)
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                No bank transactions for these filters.
            </div>
        @else
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-gray-950/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/40">
                    <h3 class="text-sm font-semibold tracking-tight text-gray-950 dark:text-white">
                        {{ $report['title'] }}
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $report['direction_label'] }} ·
                        {{ \App\Services\BankPaymentsChartReportBuilder::formatValue($report['grand_total'], $report['metric']) }}
                    </p>
                </div>
                <div class="p-4">
                    <div class="relative h-96 w-full min-h-[24rem]">
                        <canvas id="bank-payments-chart-canvas"></canvas>
                    </div>
                </div>
            </section>
        @endif
    </div>

    @assets
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" data-navigate-track></script>
    @endassets

    @script
    <script>
        let bankPaymentsChart = null;

        const formatBankPaymentsValue = (amount, metric) => {
            const value = Number(amount ?? 0);
            if (! Number.isFinite(value)) {
                return '—';
            }

            if (metric === 'count') {
                return new Intl.NumberFormat().format(Math.round(value));
            }

            return new Intl.NumberFormat(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(value) + ' €';
        };

        const renderBankPaymentsChart = () => {
            const root = document.getElementById('bank-payments-chart-root');
            const canvas = document.getElementById('bank-payments-chart-canvas');
            const jsonEl = document.getElementById('bank-payments-chart-json');

            if (! root || root.dataset.hasTransactions !== '1' || ! canvas || ! jsonEl) {
                if (bankPaymentsChart) {
                    try { bankPaymentsChart.destroy(); } catch (e) {}
                    bankPaymentsChart = null;
                }
                return;
            }

            if (typeof window.Chart === 'undefined') {
                setTimeout(renderBankPaymentsChart, 100);
                return;
            }

            let report = {};
            try {
                report = JSON.parse(jsonEl.textContent || '{}');
            } catch (error) {
                console.error(error);
                return;
            }

            const days = Array.isArray(report.days) ? report.days : [];
            if (days.length === 0) {
                return;
            }

            const metric = report.metric === 'count' ? 'count' : 'sum';
            const labels = days.map((day) => day.label ?? day.day);
            const values = days.map((day) => Number(day.value ?? 0));

            if (bankPaymentsChart) {
                try { bankPaymentsChart.destroy(); } catch (e) {}
                bankPaymentsChart = null;
            }

            bankPaymentsChart = new Chart(canvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: metric === 'count' ? 'Transactions' : 'Amount (€)',
                        data: values,
                        backgroundColor: metric === 'count'
                            ? 'rgba(115, 115, 115, 0.85)'
                            : 'rgba(17, 17, 17, 0.85)',
                        borderColor: metric === 'count'
                            ? 'rgba(82, 82, 82, 1)'
                            : 'rgba(17, 17, 17, 1)',
                        borderWidth: 1,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { title: { display: true, text: 'Days' } },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: metric === 'count' ? 'Count' : 'Sum (€)',
                            },
                            ticks: metric === 'count' ? { precision: 0, stepSize: 1 } : {},
                        },
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: (context) => formatBankPaymentsValue(Number(context.parsed.y ?? 0), metric),
                            },
                        },
                    },
                },
            });
        };

        renderBankPaymentsChart();

        Livewire.hook('morph.updated', () => {
            requestAnimationFrame(() => renderBankPaymentsChart());
        });
    </script>
    @endscript
</x-filament-panels::page>
