<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Pelno (nuostolių) ataskaita — income class 5 and expense class 6 for the selected period.</p>
            </div>
            <div class="fi-report-filters__actions shrink-0">
                {{ $this->generateReportAction }}
            </div>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @php
        $statement = $this->statement();
        $filters = $this->filterContext();
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Period summary',
        'description' => $filters['period_label'].' · '.$filters['supplier_label'],
        'footer' => $statement['is_profit'] ? 'Net profit for the period.' : 'Net loss for the period.',
        'stats' => [
            ['label' => 'Revenue', 'value' => \App\Support\Money::format($statement['revenue']), 'hint' => 'Class 5 income'],
            ['label' => 'Expenses', 'value' => \App\Support\Money::format($statement['expenses']), 'hint' => 'Class 6 expenses'],
            ['label' => 'Gross profit', 'value' => \App\Support\Money::format($statement['gross']), 'hint' => 'Sales − cost of sales'],
            [
                'label' => $statement['is_profit'] ? 'Net profit' : 'Net loss',
                'value' => \App\Support\Money::format($statement['net']),
                'hint' => 'Pelno (nuostolių) ataskaita',
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])

    @include('filament.admin.components.profit-loss-statement', [
        'title' => 'Profit (loss) statement',
        'description' => 'Standard Lithuanian layout. Income = credit − debit. Expenses = debit − credit.',
        'statement' => $statement,
        'class' => 'mt-6',
    ])

    <div class="mt-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
