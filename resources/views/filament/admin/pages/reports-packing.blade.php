<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Filter packing data by date, package profile, and internal vs export customers.</p>
            </div>
            <div class="fi-report-filters__actions shrink-0 flex flex-wrap items-center justify-end gap-2">
                {{ $this->generateInternalReportAction }}
                {{ $this->generateExportReportAction }}
            </div>
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
        $filters = $this->filterContext();
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Packing summary',
        'description' => $filters['period_label'].' · '.$filters['package_label'].' · '.$filters['audience_label'],
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
