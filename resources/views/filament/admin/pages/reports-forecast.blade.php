<x-filament-panels::page>
    @php($report = $this->report())

    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Filters</h2>
            <p class="fi-report-filters__description">
                Demand from sold orders (lookback) projected over the horizon, compared with on-hand stock and inbound cargo.
                Stable customers (e.g. BIKINIS BROS) keep a demand floor so their usual colors are never under-ordered.
            </p>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Forecast summary',
        'description' => $report['lookback_from'].' → '.$report['lookback_until']
            .' lookback · horizon to '.$report['horizon_until']
            .(count($report['stable_customers']) ? ' · stable: '.collect($report['stable_customers'])->pluck('label')->join(', ') : ''),
        'footer' => $report['summary']['colors'].' color(s) shown · '.$report['summary']['needs_order'].' need ordering.',
        'stats' => [
            ['label' => 'Colors', 'value' => number_format($report['summary']['colors']), 'hint' => 'In table'],
            ['label' => 'Sold (lookback)', 'value' => \App\Services\ForecastReportBuilder::formatMeters($report['summary']['sold_m']), 'hint' => $report['lookback_days'].' days'],
            ['label' => 'Buy meters', 'value' => \App\Services\ForecastReportBuilder::formatMeters($report['summary']['buy_m']), 'hint' => 'Suggested', 'emphasis' => true],
            ['label' => 'Buy rolls', 'value' => number_format($report['summary']['buy_rolls']), 'hint' => '≈ 30 m rolls'],
        ],
        'class' => 'mt-6',
    ])

    <div
        wire:key="forecast-chart-{{ $report['summary']['colors'] }}-{{ $report['summary']['buy_m'] }}-{{ ($this->data['lookback_days'] ?? 0) }}-{{ ($this->data['horizon_days'] ?? 0) }}-{{ (int) ($this->data['only_needs_order'] ?? 1) }}"
        class="mt-6"
        x-data="forecastChartPage()"
        x-init="init()"
    >
        <script type="application/json" x-ref="forecastChartJson">@json($report['chart'] ?? ['by_collection' => [], 'top_colors' => []])</script>

        @if (($report['summary']['colors'] ?? 0) === 0 || (count($report['chart']['by_collection'] ?? []) === 0 && count($report['chart']['top_colors'] ?? []) === 0))
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                No forecast data to chart for these filters.
            </div>
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                <section
                    class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-gray-950/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10"
                    wire:ignore
                >
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/40">
                        <h3 class="text-sm font-semibold tracking-tight text-gray-950 dark:text-white">
                            Buy by collection
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Suggested meters
                        </p>
                    </div>
                    <div class="p-4">
                        <div class="relative h-80 w-full min-h-[20rem]">
                            <canvas x-ref="collectionCanvas"></canvas>
                        </div>
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-gray-950/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10"
                    wire:ignore
                >
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/40">
                        <h3 class="text-sm font-semibold tracking-tight text-gray-950 dark:text-white">
                            Top colors to buy
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            On hand · inbound · buy (m)
                        </p>
                    </div>
                    <div class="p-4">
                        <div class="relative h-80 w-full min-h-[20rem]">
                            <canvas x-ref="colorsCanvas"></canvas>
                        </div>
                    </div>
                </section>
            </div>
        @endif
    </div>

    <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm ring-1 ring-gray-950/5 dark:border-gray-700 dark:bg-gray-900 dark:ring-white/10">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800/50 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Collection</th>
                        <th class="px-4 py-3">Color</th>
                        <th class="px-4 py-3 text-right">Sold</th>
                        <th class="px-4 py-3 text-right">Expected</th>
                        <th class="px-4 py-3 text-right">Stable floor</th>
                        <th class="px-4 py-3 text-right">Recommended</th>
                        <th class="px-4 py-3 text-right">On hand</th>
                        <th class="px-4 py-3 text-right">Inbound</th>
                        <th class="px-4 py-3 text-right">Buy</th>
                        <th class="px-4 py-3 text-right">Rolls</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($report['rows'] as $row)
                        <tr @class([
                            'bg-amber-50/60 dark:bg-amber-500/5' => $row['stable'] && $row['buy_m'] > 0,
                        ])>
                            <td class="px-4 py-2.5 font-medium text-gray-950 dark:text-white">{{ $row['collection'] }}</td>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-200">
                                <span class="font-medium">{{ $row['color_code'] }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $row['color_name'] }}</span>
                                @if ($row['stable'])
                                    <span class="ml-1 inline-flex rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">Stable</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ \App\Services\ForecastReportBuilder::formatMeters($row['sold_m']) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ \App\Services\ForecastReportBuilder::formatMeters($row['expected_m']) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ \App\Services\ForecastReportBuilder::formatMeters($row['stable_floor_m']) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ \App\Services\ForecastReportBuilder::formatMeters($row['recommended_m']) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ \App\Services\ForecastReportBuilder::formatMeters($row['on_hand_m']) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ \App\Services\ForecastReportBuilder::formatMeters($row['inbound_m']) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums font-semibold text-primary-600 dark:text-primary-400">
                                {{ \App\Services\ForecastReportBuilder::formatMeters($row['buy_m']) }}
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums font-semibold">
                                {{ $row['buy_rolls'] > 0 ? number_format($row['buy_rolls']) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                No colors match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function forecastChartPage() {
            return {
                charts: [],
                formatMeters(amount) {
                    const value = Number(amount ?? 0);
                    if (! Number.isFinite(value) || Math.abs(value) < 0.005) {
                        return '—';
                    }

                    return new Intl.NumberFormat(undefined, {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 1,
                    }).format(value) + ' m';
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

                    this.$nextTick(() => {
                        requestAnimationFrame(() => this.renderCharts());
                    });
                },
                renderCharts() {
                    this.destroyCharts();

                    let payload = {};
                    try {
                        payload = JSON.parse(this.$refs.forecastChartJson?.textContent ?? '{}');
                    } catch (error) {
                        console.error(error);
                        return;
                    }

                    const collections = Array.isArray(payload.by_collection) ? payload.by_collection : [];
                    const topColors = Array.isArray(payload.top_colors) ? payload.top_colors : [];

                    if (this.$refs.collectionCanvas && collections.length > 0) {
                        const existing = typeof Chart.getChart === 'function'
                            ? Chart.getChart(this.$refs.collectionCanvas)
                            : null;
                        if (existing) {
                            existing.destroy();
                        }

                        this.charts.push(new Chart(this.$refs.collectionCanvas.getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels: collections.map((row) => row.label ?? ''),
                                datasets: [
                                    {
                                        label: 'Buy',
                                        data: collections.map((row) => Number(row.buy_m ?? 0)),
                                        backgroundColor: 'rgba(235, 128, 39, 0.9)',
                                        borderColor: 'rgba(235, 128, 39, 1)',
                                        borderWidth: 1,
                                        borderRadius: 4,
                                    },
                                    {
                                        label: 'Sold (lookback)',
                                        data: collections.map((row) => Number(row.sold_m ?? 0)),
                                        backgroundColor: 'rgba(34, 34, 34, 0.75)',
                                        borderColor: 'rgba(34, 34, 34, 1)',
                                        borderWidth: 1,
                                        borderRadius: 4,
                                    },
                                ],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                animation: false,
                                plugins: {
                                    legend: { position: 'bottom' },
                                    tooltip: {
                                        callbacks: {
                                            label: (context) => {
                                                const label = context.dataset?.label ?? '';
                                                return label + ': ' + this.formatMeters(context.parsed?.y ?? 0);
                                            },
                                        },
                                    },
                                },
                                scales: {
                                    x: {
                                        ticks: { maxRotation: 45, minRotation: 0 },
                                    },
                                    y: {
                                        beginAtZero: true,
                                        title: { display: true, text: 'Meters' },
                                    },
                                },
                            },
                        }));
                    }

                    if (this.$refs.colorsCanvas && topColors.length > 0) {
                        const existing = typeof Chart.getChart === 'function'
                            ? Chart.getChart(this.$refs.colorsCanvas)
                            : null;
                        if (existing) {
                            existing.destroy();
                        }

                        this.charts.push(new Chart(this.$refs.colorsCanvas.getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels: topColors.map((row) => row.label ?? ''),
                                datasets: [
                                    {
                                        label: 'On hand',
                                        data: topColors.map((row) => Number(row.on_hand_m ?? 0)),
                                        backgroundColor: 'rgba(34, 34, 34, 0.8)',
                                        borderColor: 'rgba(34, 34, 34, 1)',
                                        borderWidth: 1,
                                    },
                                    {
                                        label: 'Inbound',
                                        data: topColors.map((row) => Number(row.inbound_m ?? 0)),
                                        backgroundColor: 'rgba(201, 201, 201, 0.95)',
                                        borderColor: 'rgba(160, 160, 160, 1)',
                                        borderWidth: 1,
                                    },
                                    {
                                        label: 'Buy',
                                        data: topColors.map((row) => Number(row.buy_m ?? 0)),
                                        backgroundColor: 'rgba(235, 128, 39, 0.9)',
                                        borderColor: 'rgba(235, 128, 39, 1)',
                                        borderWidth: 1,
                                    },
                                ],
                            },
                            options: {
                                indexAxis: 'y',
                                responsive: true,
                                maintainAspectRatio: false,
                                animation: false,
                                plugins: {
                                    legend: { position: 'bottom' },
                                    tooltip: {
                                        callbacks: {
                                            label: (context) => {
                                                const label = context.dataset?.label ?? '';
                                                return label + ': ' + this.formatMeters(context.parsed?.x ?? 0);
                                            },
                                        },
                                    },
                                },
                                scales: {
                                    x: {
                                        beginAtZero: true,
                                        title: { display: true, text: 'Meters' },
                                    },
                                    y: {
                                        ticks: {
                                            autoSkip: false,
                                            font: { size: 10 },
                                        },
                                    },
                                },
                            },
                        }));
                    }
                },
            };
        }
    </script>
</x-filament-panels::page>
