<x-filament-panels::page>
    @php($report = $this->report())

    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Filters</h2>
            <p class="fi-report-filters__description">
                Sold order items by color, grouped by collection. Catalog products and draft/pending/rejected orders are excluded.
            </p>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @include('filament.admin.components.report-stat-summary', [
        'title' => $report['title'],
        'description' => $report['period_label'].' · '.$report['metric_label'].' · catalog products excluded',
        'footer' => $report['orders'].' order(s) · '.$report['colors'].' color(s) across '.count($report['collections']).' collection(s).',
        'stats' => $report['colors'] === 0 ? [] : [
            ['label' => 'Orders', 'value' => number_format($report['orders'])],
            ['label' => 'Collections', 'value' => number_format(count($report['collections']))],
            ['label' => 'Colors', 'value' => number_format($report['colors'])],
            [
                'label' => $report['metric'] === \App\Services\OrdersChartReportBuilder::METRIC_ROLLS ? 'Total rolls' : 'Total meters',
                'value' => \App\Services\OrdersChartReportBuilder::formatValue($report['grand_total'], $report['metric']),
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])

    <div
        wire:key="orders-chart-{{ $report['metric'] }}-{{ $report['colors'] }}-{{ $report['orders'] }}-{{ md5(json_encode([$report['from'], $report['until'], $this->data['customer_ids'] ?? [], $this->data['countries'] ?? []])) }}"
        class="mt-6 space-y-6"
        x-data="ordersChartPage()"
        x-init="init()"
    >
        <script type="application/json" x-ref="ordersChartJson">@json($report)</script>

        @forelse ($report['collections'] as $collection)
            <section
                class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-gray-950/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10"
                wire:ignore
            >
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/40">
                    <h3 class="text-sm font-semibold tracking-tight text-gray-950 dark:text-white">
                        {{ $collection['label'] }}
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ count($collection['colors']) }} color(s) ·
                        {{ \App\Services\OrdersChartReportBuilder::formatValue($collection['total'], $report['metric']) }}
                    </p>
                </div>
                <div class="p-4">
                    <div class="relative h-80 w-full min-h-[20rem]">
                        <canvas data-collection-id="{{ $collection['id'] }}"></canvas>
                    </div>
                </div>
            </section>
        @empty
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                No sold items to chart for these filters.
            </div>
        @endforelse
    </div>

    <script>
        function ordersChartPage() {
            return {
                metric: 'meters',
                collections: [],
                charts: [],
                formatValue(amount) {
                    const value = Number(amount ?? 0);
                    if (! Number.isFinite(value) || Math.abs(value) < 0.005) {
                        return '—';
                    }

                    if (this.metric === 'rolls') {
                        return new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 }).format(value) + ' rolls';
                    }

                    return new Intl.NumberFormat(undefined, {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 2,
                    }).format(value) + ' m';
                },
                barColor(color, index) {
                    const hex = typeof color?.hex === 'string' ? color.hex.trim() : '';
                    if (/^#[0-9A-Fa-f]{6}$/.test(hex)) {
                        return hex;
                    }

                    const palette = [
                        '#2563eb', '#059669', '#d97706', '#7c3aed', '#db2777',
                        '#0891b2', '#4f46e5', '#ca8a04', '#dc2626', '#0f766e',
                    ];

                    return palette[index % palette.length];
                },
                destroyCharts() {
                    (this.charts ?? []).forEach((chart) => {
                        try {
                            chart.destroy();
                        } catch (error) {
                            // Ignore charts already detached by Livewire morphs.
                        }
                    });
                    this.charts = [];
                },
                init() {
                    if (typeof Chart === 'undefined') {
                        setTimeout(() => this.init(), 120);
                        return;
                    }

                    try {
                        const report = JSON.parse(this.$refs.ordersChartJson?.textContent ?? '{}');
                        this.metric = report.metric ?? 'meters';
                        this.collections = Array.isArray(report.collections) ? report.collections : [];
                    } catch (error) {
                        console.error(error);
                        this.collections = [];
                    }

                    this.$nextTick(() => {
                        requestAnimationFrame(() => this.renderCharts());
                    });
                },
                renderCharts() {
                    this.destroyCharts();

                    this.collections.forEach((collection) => {
                        const canvas = this.$root.querySelector(`canvas[data-collection-id="${collection.id}"]`);
                        if (! canvas) {
                            return;
                        }

                        const existing = typeof Chart.getChart === 'function' ? Chart.getChart(canvas) : null;
                        if (existing) {
                            existing.destroy();
                        }

                        const labels = (collection.colors ?? []).map((color) => String(color.label ?? ''));
                        const stocks = (collection.colors ?? []).map((color) => {
                            const stock = Number(color.stock ?? 0);

                            return Number.isFinite(stock) ? stock : 0;
                        });
                        const colors = (collection.colors ?? []).map((color, index) => this.barColor(color, index));

                        const chart = new Chart(canvas.getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels,
                                datasets: [{
                                    label: this.metric === 'rolls' ? 'Rolls sold' : 'Meters sold',
                                    data: stocks,
                                    backgroundColor: colors,
                                    borderColor: colors,
                                    borderWidth: 1,
                                    borderRadius: 4,
                                    minBarLength: 2,
                                    categoryPercentage: 0.8,
                                    barPercentage: 0.9,
                                }],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                animation: false,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: (context) => {
                                                const color = collection.colors?.[context.dataIndex];
                                                const code = color?.code ? ` (${color.code})` : '';
                                                const stock = context.parsed?.y ?? context.raw ?? 0;

                                                return `${context.label}${code}: ${this.formatValue(stock)}`;
                                            },
                                        },
                                    },
                                },
                                scales: {
                                    x: {
                                        ticks: {
                                            maxRotation: 60,
                                            minRotation: 0,
                                            autoSkip: true,
                                        },
                                        grid: { display: false },
                                    },
                                    y: {
                                        beginAtZero: true,
                                        title: {
                                            display: true,
                                            text: this.metric === 'rolls' ? 'Rolls sold' : 'Meters sold',
                                        },
                                    },
                                },
                            },
                        });

                        this.charts.push(chart);
                        chart.resize();
                    });
                },
            };
        }
    </script>
</x-filament-panels::page>
