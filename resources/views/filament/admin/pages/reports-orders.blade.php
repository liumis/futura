<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Filters</h2>
            <p class="fi-report-filters__description">Narrow the report by date range and customer export status.</p>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    <div class="mt-6">
        {{ $this->table }}
    </div>

    @php
        $totals = $this->totals();
        $from = $this->data['from'] ?? null;
        $until = $this->data['until'] ?? null;
        $export = $this->data['export'] ?? 'all';
        $exportLabel = match ($export) {
            'yes' => 'Export customers only',
            'no' => 'Non-export customers only',
            default => 'All customers',
        };
        $periodLabel = filled($from) && filled($until)
            ? $from.' — '.$until
            : 'All dates';
        $avgOrder = $totals['orders'] > 0 ? $totals['total'] / $totals['orders'] : 0;
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Period summary',
        'description' => $periodLabel.' · '.$exportLabel,
        'footer' => $totals['orders'].' order(s) included in this report.',
        'stats' => $totals['orders'] === 0 ? [] : [
            ['label' => 'Orders', 'value' => number_format($totals['orders']), 'hint' => 'Matching orders'],
            ['label' => 'Line items', 'value' => number_format($totals['items']), 'hint' => 'Total quantity'],
            ['label' => 'Shipping', 'value' => \App\Support\Money::format($totals['shipping']), 'hint' => 'Delivery charges'],
            ['label' => 'Revenue', 'value' => \App\Support\Money::format($totals['total']), 'hint' => 'Avg '.\App\Support\Money::format($avgOrder).' per order', 'emphasis' => true],
        ],
        'class' => 'mt-6',
    ])
</x-filament-panels::page>
