<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Prekių tiekimo ir paslaugų teikimo į kitas ES valstybes nares ataskaita (FR0564) — intra-community 0% sales for the selected month.</p>
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
        'title' => 'FR0564 totals',
        'description' => $report['period_label'].' · '.$report['company_name'].(filled($report['company_id']) ? ' · įm. k. '.$report['company_id'] : ''),
        'footer' => $report['invoice_count'].' qualifying invoice(s) aggregated into '.$report['line_count'].' partner line(s). Lithuanian customers and taxable / exempt rates are excluded.',
        'stats' => [
            ['label' => 'Partner lines', 'value' => (string) $report['line_count'], 'hint' => 'Fields 10–12'],
            ['label' => '13 · Goods', 'value' => $report['field15_label'], 'hint' => 'Intra-community supplies', 'emphasis' => true],
            ['label' => '16 · All pages', 'value' => $report['field16_label'], 'hint' => 'Sum of field 13'],
            ['label' => '17 · Extra sheets', 'value' => $report['field17'] > 0 ? (string) $report['field17'] : '—', 'hint' => 'Additional pages'],
        ],
        'class' => 'mt-6',
    ])

    <div class="mt-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
