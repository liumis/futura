<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header flex items-start justify-between gap-4">
            <div class="fi-report-filters__heading min-w-0">
                <h2 class="fi-report-filters__title">Filters</h2>
                <p class="fi-report-filters__description">Didžioji apskaitos knyga — opening balance, period turnover, and closing balance by account.</p>
            </div>
            <div class="fi-report-filters__actions shrink-0 flex flex-wrap items-center gap-2">
                {{ $this->recalculateLedgerAction }}
                {{ $this->generateReportAction }}
            </div>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->form }}
        </div>
    </section>

    @php
        $coverage = $this->ledgerCoverage();
    @endphp

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Ledger coverage from 2026-04-01',
        'description' => 'Invoice finance lines + payroll GL accruals (opening balances = zero).',
        'footer' => 'Use Recalculate from 2026-04-01 to regenerate defaults.',
        'stats' => [
            [
                'label' => 'Invoices with lines',
                'value' => number_format($coverage['invoices_with_lines']).' / '.number_format($coverage['invoices_total']),
                'hint' => $coverage['invoices_missing_lines'] > 0
                    ? $coverage['invoices_missing_lines'].' missing'
                    : 'Complete',
            ],
            [
                'label' => 'Unbalanced invoices',
                'value' => number_format($coverage['invoices_unbalanced']),
                'hint' => $coverage['invoices_unbalanced'] === 0 ? 'OK' : 'Check finance details',
            ],
            [
                'label' => 'Payroll months posted',
                'value' => number_format($coverage['payroll_months_posted']).' / '.number_format($coverage['payroll_months_total']),
                'hint' => 'GL-DU-YYYY-MM carriers',
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])

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
        'footer' => $totals['accounts'].' account(s) · opening, turnover, and closing debit should equal credit when the books balance.',
        'stats' => $totals['accounts'] === 0 ? [] : [
            ['label' => 'Accounts', 'value' => number_format($totals['accounts']), 'hint' => 'Invoice codes'],
            [
                'label' => 'Opening',
                'value' => \App\Support\Money::format($totals['opening_debit']).' / '.\App\Support\Money::format($totals['opening_credit']),
                'hint' => $totals['opening_balanced'] ? 'Debit = credit' : 'Not balanced',
            ],
            [
                'label' => 'Turnover',
                'value' => \App\Support\Money::format($totals['turnover_debit']).' / '.\App\Support\Money::format($totals['turnover_credit']),
                'hint' => $totals['turnover_balanced'] ? 'Debit = credit' : 'Not balanced',
            ],
            [
                'label' => 'Closing',
                'value' => \App\Support\Money::format($totals['closing_debit']).' / '.\App\Support\Money::format($totals['closing_credit']),
                'hint' => $totals['closing_balanced'] ? 'Debit = credit' : 'Not balanced',
                'emphasis' => true,
            ],
        ],
        'class' => 'mt-6',
    ])
</x-filament-panels::page>
