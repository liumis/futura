<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Filters</h2>
            <p class="fi-report-filters__description">Narrow the report by date range and supplier.</p>
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
        $supplierId = $this->data['supplier_id'] ?? 'all';
        $supplierLabel = $supplierId === 'all' || blank($supplierId)
            ? 'All suppliers'
            : (\App\Models\Supplier::query()->find($supplierId)?->name ?? 'Selected supplier');
        $periodLabel = filled($from) && filled($until)
            ? $from.' — '.$until
            : 'All dates';
        $avgOrder = $totals['orders'] > 0 ? $totals['total'] / $totals['orders'] : 0;
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Period summary',
        'description' => $periodLabel.' · '.$supplierLabel,
        'footer' => $totals['orders'].' warehouse order(s) included in this report.',
        'stats' => $totals['orders'] === 0 ? [] : [
            ['label' => 'Orders', 'value' => number_format($totals['orders']), 'hint' => 'Matching warehouse orders'],
            ['label' => 'Line items', 'value' => number_format($totals['items']), 'hint' => 'Total quantity'],
            ['label' => 'Shipping', 'value' => \App\Support\Money::format($totals['shipping']), 'hint' => 'Shipping charges'],
            ['label' => 'Full cost', 'value' => \App\Support\Money::format($totals['total']), 'hint' => 'Avg '.\App\Support\Money::format($avgOrder).' per order', 'emphasis' => true],
        ],
        'class' => 'mt-6',
    ])
</x-filament-panels::page>
