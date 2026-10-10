<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Filter account balances (balansas) by invoice date and supplier.</p>
            </div>
            <div class="fi-report-filters__actions shrink-0">
                {{ $this->generateReportAction }}
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
        'title' => 'Period summary',
        'description' => $filters['period_label'].' · '.$filters['supplier_label'],
        'footer' => $totals['accounts'].' account(s) · '.$totals['lines'].' finance line(s).',
        'stats' => $totals['accounts'] === 0 ? [] : [
            ['label' => 'Accounts', 'value' => number_format($totals['accounts']), 'hint' => 'Invoice codes'],
            ['label' => 'Debit', 'value' => \App\Support\Money::format($totals['debit']), 'hint' => 'Total debit'],
            ['label' => 'Credit', 'value' => \App\Support\Money::format($totals['credit']), 'hint' => 'Total credit'],
            [
                'label' => 'Balance',
                'value' => $totals['balanced'] ? 'Balanced' : \App\Support\Money::format($totals['balance']),
                'hint' => $totals['balanced'] ? 'Debit equals credit' : 'Debit − credit',
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])
</x-filament-panels::page>
