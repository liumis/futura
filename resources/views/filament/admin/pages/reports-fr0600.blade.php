<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Mokestinio laikotarpio PVM deklaracija (FR0600) — sales and purchase invoices for the selected month.</p>
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
        $report = $this->report();
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'FR0600 totals',
        'description' => $report['period_label'].' · '.$report['company_name'].(filled($report['company_id']) ? ' · įm. k. '.$report['company_id'] : ''),
        'footer' => $report['sales_count'].' sales invoice(s) · '.$report['purchase_count'].' purchase invoice(s). Unclassified invoices are excluded from the form totals.',
        'stats' => [
            ['label' => '11 · Taxable sales', 'value' => $report['labels']['11'], 'hint' => 'Net of standard / reduced rates'],
            ['label' => '29 · Output VAT 21%', 'value' => $report['labels']['29'], 'hint' => 'Standard rate'],
            ['label' => '25 · Input VAT', 'value' => $report['labels']['25'], 'hint' => 'Purchase invoices'],
            [
                'label' => $report['is_payable'] ? '36 · VAT payable' : '36 · VAT refundable',
                'value' => $report['labels']['36'],
                'hint' => 'Output − deductible',
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])

    <div class="mt-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
