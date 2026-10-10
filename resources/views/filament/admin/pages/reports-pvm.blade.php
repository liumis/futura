<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Pridėtinės vertės mokestis — output VAT from sales invoices, input VAT from purchase invoices.</p>
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
        $summary = $this->summary();
        $filters = $this->filterContext();
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Period summary',
        'description' => $filters['period_label'].' · '.$filters['company_label'].' · '.$filters['direction_label'],
        'footer' => $summary['invoices'].' invoice(s) · Unclassified VAT is not included in payable.',
        'stats' => $summary['invoices'] === 0 ? [] : [
            ['label' => 'Output VAT', 'value' => \App\Support\Money::format($summary['output_vat']), 'hint' => 'Pardavimo PVM'],
            ['label' => 'Input VAT', 'value' => \App\Support\Money::format($summary['input_vat']), 'hint' => 'Pirkimo PVM'],
            ['label' => 'Sales net', 'value' => \App\Support\Money::format($summary['sales_base']), 'hint' => 'Sum without VAT'],
            [
                'label' => $summary['is_payable'] ? 'PVM payable' : 'PVM refundable',
                'value' => \App\Support\Money::format($summary['payable']),
                'hint' => 'Output − input',
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])

    @include('filament.admin.components.pvm-rate-summary', [
        'title' => 'By PVM rate',
        'description' => 'Grouped by VAT classificator. Payable = output VAT − input VAT.',
        'summary' => $summary,
        'class' => 'mt-6',
    ])

    <div class="mt-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
