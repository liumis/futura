<x-filament-panels::page>
    @php($report = $this->report())

    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Filters</h2>
            <p class="fi-report-filters__description">Current stock by color, grouped by collection. Catalog products are excluded.</p>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @include('filament.admin.components.report-stat-summary', [
        'title' => $report['title'],
        'description' => $report['metric_label'].' · catalog products excluded'
            .($report['metric'] === \App\Services\StockChartReportBuilder::METRIC_METERS
                ? ' · per-color recommended stock'
                    .($report['low_stock_limit'] > 0 ? ' · global low stock line at '.$report['low_stock_limit_label'] : '')
                : ''),
        'footer' => $report['colors'].' color(s) across '.count($report['collections']).' collection(s).',
        'stats' => $report['colors'] === 0 ? [] : [
            ['label' => 'Collections', 'value' => number_format(count($report['collections']))],
            ['label' => 'Colors', 'value' => number_format($report['colors'])],
            [
                'label' => 'Low stock',
                'value' => $report['low_stock_limit'] > 0 ? $report['low_stock_limit_label'] : 'Not set',
                'hint' => 'Click to edit',
                'action' => 'editLowStockLimit',
            ],
            [
                'label' => $report['metric'] === \App\Services\StockChartReportBuilder::METRIC_ROLLS ? 'Total rolls' : 'Total meters',
                'value' => \App\Services\StockChartReportBuilder::formatValue($report['grand_total'], $report['metric']),
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])

    <div
        wire:key="stock-chart-{{ $report['metric'] }}-{{ $report['colors'] }}-{{ count($report['collections']) }}-{{ $report['low_stock_limit'] }}"
        class="mt-6 space-y-6"
        x-data="stockChartPage()"
        x-init="init()"
    >
        <script type="application/json" x-ref="stockChartJson">@json($report)</script>

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
                        {{ \App\Services\StockChartReportBuilder::formatValue($collection['total'], $report['metric']) }}
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
                No stock to chart for this metric.
            </div>
        @endforelse
    </div>

    <script>
        function stockChartPage() {
            return {
                metric: 'meters',
                collections: [],
                lowStockLimit: 0,
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
                        const report = JSON.parse(this.$refs.stockChartJson?.textContent ?? '{}');
                        this.metric = report.metric ?? 'meters';
                        this.collections = Array.isArray(report.collections) ? report.collections : [];
                        this.lowStockLimit = Number(report.low_stock_limit ?? 0);
                    } catch (error) {
                        console.error(error);
                        this.collections = [];
                        this.lowStockLimit = 0;
                    }

                    this.$nextTick(() => {
                        requestAnimationFrame(() => this.renderCharts());
                    });
                },
                lowStockAnnotation() {
                    if (this.metric !== 'meters' || ! (this.lowStockLimit > 0)) {
                        return {};
                    }

                    return {
                        lowStockLine: {
                            type: 'line',
                            yMin: this.lowStockLimit,
                            yMax: this.lowStockLimit,
                            borderColor: '#9f1239',
                            borderWidth: 2,
                            borderDash: [6, 4],
                            label: {
                                display: true,
                                content: 'Global low stock ' + this.formatValue(this.lowStockLimit),
                                position: 'end',
                                backgroundColor: 'rgba(159, 18, 57, 0.9)',
                                color: '#fff',
                                font: {
                                    size: 10,
                                    weight: '600',
                                },
                                padding: 4,
                            },
                        },
                    };
                },
                renderCharts() {
                    this.destroyCharts();

                    this.collections.forEach((collection) => {
                        const canvas = this.$root.querySelector(`canvas[data-collection-id="${collection.id}"]`);
                        if (! canvas) {
                            return;
                        }

                        // Reset any Chart.js instance left on this canvas.
                        const existing = typeof Chart.getChart === 'function' ? Chart.getChart(canvas) : null;
                        if (existing) {
                            existing.destroy();
                        }

                        const labels = (collection.colors ?? []).map((color) => String(color.label ?? ''));
                        const stocks = (collection.colors ?? []).map((color) => {
                            const stock = Number(color.stock ?? color.value ?? 0);

                            return Number.isFinite(stock) ? stock : 0;
                        });
                        const recommended = (collection.colors ?? []).map((color) => {
                            if (this.metric !== 'meters' || color.recommended === null || color.recommended === undefined) {
                                return null;
                            }

                            const value = Number(color.recommended);

                            return Number.isFinite(value) && value > 0 ? value : null;
                        });
                        const hasRecommended = recommended.some((value) => value !== null);
                        const colors = (collection.colors ?? []).map((color, index) => this.barColor(color, index));
                        const suggestedMax = Math.max(
                            ...stocks,
                            ...recommended.map((value) => value ?? 0),
                            this.metric === 'meters' && this.lowStockLimit > 0 ? this.lowStockLimit : 0,
                            0,
                        );

                        const datasets = [{
                            type: 'bar',
                            label: this.metric === 'rolls' ? 'Current stock' : 'Current stock',
                            data: stocks,
                            backgroundColor: colors,
                            borderColor: colors,
                            borderWidth: 1,
                            borderRadius: 4,
                            minBarLength: 2,
                            categoryPercentage: 0.8,
                            barPercentage: 0.9,
                            order: 2,
                        }];

                        if (hasRecommended) {
                            datasets.push({
                                type: 'line',
                                label: 'Recommended stock',
                                data: recommended,
                                borderColor: '#dc2626',
                                backgroundColor: '#dc2626',
                                borderWidth: 2,
                                pointRadius: 4,
                                pointHoverRadius: 5,
                                pointBackgroundColor: '#dc2626',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 1,
                                spanGaps: false,
                                tension: 0,
                                order: 1,
                            });
                        }

                        const chart = new Chart(canvas.getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels,
                                datasets,
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                animation: false,
                                plugins: {
                                    legend: {
                                        display: hasRecommended,
                                        position: 'top',
                                        align: 'end',
                                        labels: {
                                            boxWidth: 12,
                                            boxHeight: 12,
                                            usePointStyle: true,
                                            pointStyle: 'circle',
                                            filter: (item) => item.text === 'Recommended stock' || item.text === 'Current stock',
                                        },
                                    },
                                    annotation: {
                                        annotations: this.lowStockAnnotation(),
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: (context) => {
                                                const color = collection.colors?.[context.dataIndex];
                                                const code = color?.code ? ` (${color.code})` : '';
                                                const value = context.parsed?.y ?? context.raw ?? 0;
                                                const series = context.dataset?.label ?? 'Stock';

                                                if (value === null || value === undefined || ! Number.isFinite(Number(value))) {
                                                    return `${series}: —`;
                                                }

                                                return `${series}${code}: ${this.formatValue(value)}`;
                                            },
                                            afterBody: (items) => {
                                                if (this.metric !== 'meters' || ! items?.length) {
                                                    return [];
                                                }

                                                const color = collection.colors?.[items[0].dataIndex];
                                                if (! color) {
                                                    return [];
                                                }

                                                const lines = [];
                                                const stock = Number(color.stock ?? 0);
                                                const rec = color.recommended === null || color.recommended === undefined
                                                    ? null
                                                    : Number(color.recommended);

                                                if (rec !== null && Number.isFinite(rec) && rec > 0) {
                                                    lines.push('Recommended: ' + this.formatValue(rec));
                                                    if (Number.isFinite(stock)) {
                                                        const delta = stock - rec;
                                                        lines.push(
                                                            delta < 0
                                                                ? 'Below recommended by ' + this.formatValue(Math.abs(delta))
                                                                : 'Above recommended by ' + this.formatValue(delta),
                                                        );
                                                    }
                                                }

                                                return lines;
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
                                        suggestedMax: suggestedMax > 0 ? suggestedMax * 1.05 : undefined,
                                        title: {
                                            display: true,
                                            text: this.metric === 'rolls' ? 'Rolls' : 'Meters',
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
