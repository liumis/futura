<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Mėnesinė pajamų mokesčio deklaracija (GPM313) — employment payroll and dividend GPM for the selected month.</p>
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
        'title' => 'Class A employment',
        'description' => $report['period_label'].' · '.$report['company_name'].(filled($report['company_id']) ? ' · įm. k. '.$report['company_id'] : ''),
        'footer' => $report['employment_count'].' payroll payment(s). Cancelled payments are excluded. GPM is split by payment date (until 15th / from 16th).',
        'stats' => [
            ['label' => '5 · Gross', 'value' => $report['field5_label'], 'hint' => 'Before NPD, GPM, VSD, PSD'],
            ['label' => '6 · GPM until 15th', 'value' => $report['field6_label'], 'hint' => 'Payments on days 1–15'],
            ['label' => '7 · GPM from 16th', 'value' => $report['field7_label'], 'hint' => 'Payments on days 16–end', 'emphasis' => true],
        ],
        'class' => 'mt-6',
    ])

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Class A other / Class B',
        'description' => 'Non-employment Class A is not tracked yet. Class B uses dividend payments with withheld GPM.',
        'footer' => $report['dividend_count'].' dividend payment(s).',
        'stats' => [
            ['label' => '8 · Other Class A', 'value' => $report['field8_label'], 'hint' => 'No source in payroll'],
            ['label' => '11 · Class B gross', 'value' => $report['field11_label'], 'hint' => 'Dividends before GPM'],
            ['label' => '12 · Class B GPM', 'value' => $report['field12_label'], 'hint' => 'Withheld from dividends', 'emphasis' => true],
        ],
        'class' => 'mt-6',
    ])

    <div class="mt-6">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
