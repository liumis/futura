<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Filters</h2>
            <p class="fi-report-filters__description">Filter finance lines by invoice date and supplier.</p>
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
        $contactId = $this->data['contact_id'] ?? null;
        $supplierLabel = filled($contactId)
            ? (\App\Models\Contact::query()->find($contactId)?->company_name ?? 'Selected supplier')
            : 'All suppliers';
        $periodLabel = filled($from) && filled($until)
            ? $from.' — '.$until
            : 'All dates';
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Period summary',
        'description' => $periodLabel.' · '.$supplierLabel,
        'footer' => $totals['lines'].' line(s) included in this report.',
        'stats' => $totals['lines'] === 0 ? [] : [
            ['label' => 'Lines', 'value' => number_format($totals['lines']), 'hint' => 'Finance lines'],
            ['label' => 'Debit', 'value' => \App\Support\Money::format($totals['debit']), 'hint' => 'Total debit'],
            ['label' => 'Credit', 'value' => \App\Support\Money::format($totals['credit']), 'hint' => 'Total credit'],
            [
                'label' => 'Balance',
                'value' => $totals['balanced'] ? 'Balanced' : \App\Support\Money::format(abs($totals['debit'] - $totals['credit'])),
                'hint' => $totals['balanced'] ? 'Debit equals credit' : 'Difference',
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])
</x-filament-panels::page>
