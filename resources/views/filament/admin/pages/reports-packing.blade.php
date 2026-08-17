<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Filters</h2>
            <p class="fi-report-filters__description">Filter packing data by date, package profile, and customer type.</p>
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
        $packageId = $this->data['package_id'] ?? 'all';
        $exportLabel = match ($export) {
            'yes' => 'Export customers only',
            'no' => 'Non-export customers only',
            default => 'All customers',
        };
        $periodLabel = filled($from) && filled($until)
            ? $from.' — '.$until
            : 'All dates';
        $packageLabel = $packageId === 'all' || ! filled($packageId)
            ? 'All package profiles'
            : 'Selected package profile only';
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Packing summary',
        'description' => $periodLabel.' · '.$packageLabel.' · '.$exportLabel,
        'footer' => $totals['orders'].' order(s) included · weights calculated from order package profiles.',
        'stats' => $totals['orders'] === 0 ? [] : [
            ['label' => 'Orders', 'value' => number_format($totals['orders'])],
            ['label' => 'Items', 'value' => number_format($totals['items'])],
            ['label' => 'Packages', 'value' => number_format($totals['packages'])],
            ['label' => 'Palletes', 'value' => number_format($totals['palletes'])],
        ],
        'class' => 'mt-6',
    ])

    @if ($totals['orders'] > 0)
        @include('filament.admin.components.report-stat-summary', [
            'title' => 'Weight breakdown',
            'description' => 'Aggregated net, gross, and packing material weights.',
            'class' => 'mt-4',
            'stats' => [
                ['label' => 'Netto', 'value' => number_format($totals['netto'], 3).' kg'],
                ['label' => 'Brutto', 'value' => number_format($totals['brutto'], 3).' kg', 'emphasis' => true],
                ['label' => 'Plastic', 'value' => number_format($totals['plastic'], 3).' kg'],
                ['label' => 'Cardboard I', 'value' => number_format($totals['cardboard_i'], 3).' kg'],
                ['label' => 'Cardboard II', 'value' => number_format($totals['cardboard_ii'], 3).' kg'],
                ['label' => 'Wood', 'value' => number_format($totals['wood'], 3).' kg'],
            ],
        ])
    @endif
</x-filament-panels::page>
