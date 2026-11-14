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
</x-filament-panels::page>
