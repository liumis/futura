<x-filament-panels::page>
    <section class="fi-report-filters">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Company details</h2>
            <p class="fi-report-filters__description">Payer details used for SEPA exports (employees, VMI, Sodra).</p>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->companyForm }}
            <div class="mt-4">
                <button
                    type="button"
                    wire:click="saveCompanyDetails"
                    class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    Save company details
                </button>
            </div>
        </div>
    </section>

    <section class="fi-report-filters mt-10">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Date</h2>
            <p class="fi-report-filters__description">People with a valid contract in the selected date’s month.</p>
        </div>
        <div class="fi-report-filters__body max-w-xs">
            {{ $this->form }}
        </div>
    </section>

    <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1100px] text-left text-sm">
                <thead class="border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-3">Person</th>
                        <th class="px-4 py-3 w-40">Hours (plan / actual)</th>
                        <th class="px-4 py-3 w-44">Base (Gross)</th>
                        <th class="px-4 py-3 w-44">Bonus (gross)</th>
                        <th class="px-4 py-3">Comment</th>
                        <th class="px-4 py-3 w-40">Schedule</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($rows as $index => $row)
                        @php
                            $isLocked = (bool) ($row['is_locked'] ?? false);
                            $status = (string) ($row['status'] ?? 'open');
                            $hasSchedule = (bool) ($row['has_schedule'] ?? false);
                            $plannedHours = (float) ($row['planned_hours'] ?? 0);
                            $actualHours = (float) ($row['actual_hours'] ?? 0);
                            $hoursMismatch = $hasSchedule && abs($plannedHours - $actualHours) > 0.001;
                        @endphp
                        <tr wire:key="monthly-payment-row-{{ $row['employee_id'] }}-{{ $data['payment_date'] ?? '' }}">
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                <div class="flex items-center gap-2">
                                    <span>{{ $row['employee_name'] }}</span>
                                    @if ($status === 'payed')
                                        <span class="rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">Payed</span>
                                    @elseif ($status === 'wrong')
                                        <span class="rounded-md bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">Cancelled</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @if ($hasSchedule)
                                    <div @class([
                                        'text-sm',
                                        'text-amber-700 dark:text-amber-400' => $hoursMismatch,
                                        'text-gray-700 dark:text-gray-200' => ! $hoursMismatch,
                                    ])>
                                        {{ number_format($plannedHours, 2) }} /
                                        {{ number_format($actualHours, 2) }} h
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ (int) ($row['planned_working_days'] ?? 0) }} /
                                        {{ (int) ($row['actual_working_days'] ?? 0) }} days
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400">No schedule</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-gray-500 dark:text-gray-400">{{ $this->moneyPrefix() }}</span>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        wire:model="rows.{{ $index }}.base_salary"
                                        @disabled($isLocked)
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-900"
                                    >
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-gray-500 dark:text-gray-400">{{ $this->moneyPrefix() }}</span>
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        wire:model="rows.{{ $index }}.bonus_payment"
                                        placeholder="—"
                                        @disabled($isLocked)
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-900"
                                    >
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <input
                                    type="text"
                                    wire:model="rows.{{ $index }}.comment"
                                    placeholder="Optional"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                >
                            </td>
                            <td class="px-4 py-3">
                                @if ($hasSchedule && ! $isLocked && $plannedHours > 0)
                                    <button
                                        type="button"
                                        wire:click="applyActualHoursToSalary({{ $index }})"
                                        class="text-xs font-semibold text-primary-700 hover:underline dark:text-primary-300"
                                        title="Set base salary = contract base × actual / plan hours"
                                    >
                                        Apply actual
                                        @if (filled($row['prorated_base_salary'] ?? null))
                                            <span class="block font-normal text-gray-500 dark:text-gray-400">
                                                → {{ $this->moneyPrefix() }}{{ $row['prorated_base_salary'] }}
                                            </span>
                                        @endif
                                    </button>
                                @elseif ($hasSchedule)
                                    <span class="text-xs text-gray-400">—</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                No people with a valid contract for this date’s month.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-3">
        <button
            type="button"
            wire:click="mountAction('savePayments')"
            class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
        >
            Save
        </button>
        <button
            type="button"
            wire:click="applyActualHoursToAllSalaries"
            wire:confirm="Update all unlocked base salaries using contract base × actual / plan hours for this month?"
            class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
        >
            Apply actual hours to all
        </button>
    </div>

    <section class="fi-report-filters mt-10">
        <div class="fi-report-filters__header">
            <h2 class="fi-report-filters__title">Payment reports</h2>
            <p class="fi-report-filters__description">Saved payments filtered by date range and employee.</p>
        </div>
        <div class="fi-report-filters__body">
            {{ $this->reportFiltersForm }}
        </div>
    </section>

    @php
        $inputClass = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-900';
    @endphp

    <div class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px] text-left text-sm">
                <thead class="border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-3 w-10">
                            <input
                                type="checkbox"
                                wire:model.live="selectAllReports"
                                class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                                @disabled(collect($reportRows)->filter(fn ($row) => in_array(($row['status'] ?? ''), ['open', 'payed'], true))->isEmpty())
                            >
                        </th>
                        <th class="px-4 py-3 w-44">Date</th>
                        <th class="px-4 py-3">Person</th>
                        <th class="px-4 py-3 w-40">Base (Gross)</th>
                        <th class="px-4 py-3 w-40">Bonus (gross)</th>
                        <th class="px-4 py-3">Comment</th>
                        <th class="px-4 py-3">Report</th>
                        <th class="px-4 py-3 w-52 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($reportRows as $rowId => $reportRow)
                        @php
                            $rowId = (int) ($reportRow['id'] ?? $rowId);
                            $base = (float) str_replace(',', '.', (string) ($reportRow['base_salary'] ?? 0));
                            $bonusRaw = trim((string) ($reportRow['bonus_payment'] ?? ''));
                            $bonus = $bonusRaw === '' ? 0.0 : (float) str_replace(',', '.', $bonusRaw);
                            $gross = $reportRow['gross_amount'] !== null
                                ? (string) $reportRow['gross_amount']
                                : number_format($base + $bonus, 2, '.', '');
                            $sodra = $reportRow['sodra_employee_amount'] !== null
                                ? (string) $reportRow['sodra_employee_amount']
                                : '—';
                            $sodraEmployer = $reportRow['sodra_employer_amount'] !== null
                                ? (string) $reportRow['sodra_employer_amount']
                                : '—';
                            $npd = $reportRow['npd_amount'] !== null
                                ? (string) $reportRow['npd_amount']
                                : '—';
                            $gpm = $reportRow['gpm_amount'] !== null
                                ? (string) $reportRow['gpm_amount']
                                : '—';
                            $net = $reportRow['net_amount'] !== null
                                ? (string) $reportRow['net_amount']
                                : '—';
                            $hasTaxSnapshot = $reportRow['gross_amount'] !== null
                                || $reportRow['sodra_employee_amount'] !== null
                                || $reportRow['gpm_amount'] !== null
                                || $reportRow['net_amount'] !== null;
                            $grossNum = (float) str_replace(',', '.', $gross);
                            $sodraHealth = '—';
                            $sodraPension = '—';
                            $workplaceCost = '—';
                            if ($hasTaxSnapshot && $sodra !== '—') {
                                $sodraHealth = number_format(round($grossNum * 0.0698, 2), 2, '.', '');
                                $sodraPension = number_format((float) $sodra - (float) $sodraHealth, 2, '.', '');
                            }
                            if ($hasTaxSnapshot) {
                                $workplaceCost = number_format(
                                    $grossNum + (float) ($reportRow['sodra_employer_amount'] ?? 0),
                                    2,
                                    '.',
                                    '',
                                );
                            }
                            $status = (string) ($reportRow['status'] ?? 'open');
                            $isLocked = (bool) ($reportRow['is_locked'] ?? false);
                            $isOpen = $status === 'open';
                            $isPayed = $status === 'payed';
                            $reportStatus = (string) ($reportRow['report_status'] ?? '');
                            $money = $this->moneyPrefix();
                        @endphp
                        <tr wire:key="payment-report-row-{{ $rowId }}">
                            <td class="px-4 py-3">
                                @if (in_array($status, ['open', 'payed'], true))
                                    <input
                                        type="checkbox"
                                        value="{{ $rowId }}"
                                        wire:model.live="selectedReportIds"
                                        class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                                    >
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($isLocked)
                                    <input
                                        type="date"
                                        value="{{ $reportRow['payment_date'] }}"
                                        disabled
                                        class="{{ $inputClass }}"
                                    >
                                @else
                                    <input
                                        type="date"
                                        wire:key="payment-date-{{ $rowId }}"
                                        wire:model="reportRows.{{ $rowId }}.payment_date"
                                        name="payment_date_{{ $rowId }}"
                                        id="payment_date_{{ $rowId }}"
                                        class="{{ $inputClass }}"
                                    >
                                @endif
                            </td>
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">
                                <div class="flex items-center gap-2">
                                    <span>{{ $reportRow['employee_name'] }}</span>
                                    @if ($isPayed)
                                        <span class="rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">Payed</span>
                                    @elseif ($status === 'wrong')
                                        <span class="rounded-md bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">Cancelled</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-gray-500 dark:text-gray-400">{{ $money }}</span>
                                    @if ($isLocked)
                                        <input
                                            type="number"
                                            value="{{ $reportRow['base_salary'] }}"
                                            disabled
                                            class="{{ $inputClass }}"
                                        >
                                    @else
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            wire:key="base-salary-{{ $rowId }}"
                                            wire:model.live="reportRows.{{ $rowId }}.base_salary"
                                            name="base_salary_{{ $rowId }}"
                                            id="base_salary_{{ $rowId }}"
                                            autocomplete="off"
                                            class="{{ $inputClass }}"
                                        >
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-gray-500 dark:text-gray-400">{{ $money }}</span>
                                    @if ($isLocked)
                                        <input
                                            type="number"
                                            value="{{ $reportRow['bonus_payment'] }}"
                                            disabled
                                            class="{{ $inputClass }}"
                                        >
                                    @else
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            wire:key="bonus-payment-{{ $rowId }}"
                                            wire:model.live="reportRows.{{ $rowId }}.bonus_payment"
                                            name="bonus_payment_{{ $rowId }}"
                                            id="bonus_payment_{{ $rowId }}"
                                            placeholder="—"
                                            autocomplete="off"
                                            class="{{ $inputClass }}"
                                        >
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <input
                                    type="text"
                                    wire:key="comment-{{ $rowId }}"
                                    wire:model="reportRows.{{ $rowId }}.comment"
                                    name="comment_{{ $rowId }}"
                                    id="comment_{{ $rowId }}"
                                    placeholder="Optional"
                                    autocomplete="off"
                                    class="{{ $inputClass }}"
                                >
                            </td>
                            <td class="px-4 py-3">
                                <div class="space-y-1">
                                    @if ($reportStatus === 'confirmed')
                                        <span class="rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">{{ $reportRow['report_status_label'] }}</span>
                                    @elseif ($reportStatus === 'waiting_confirmations')
                                        <span class="rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ $reportRow['report_status_label'] }}</span>
                                    @elseif ($reportStatus === 'created')
                                        <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $reportRow['report_status_label'] }}</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif

                                    @if ($reportStatus === 'waiting_confirmations' && filled($reportRow['pending_approvers_label'] ?? null))
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            Missing: {{ $reportRow['pending_approvers_label'] }}
                                        </p>
                                    @endif

                                    @if ($reportRow['can_confirm'] ?? false)
                                        <button
                                            type="button"
                                            wire:click="mountAction('confirmPaymentReport', { reportId: {{ $reportRow['report_id'] }} })"
                                            class="inline-flex items-center rounded-lg bg-warning-600 px-2.5 py-1 text-xs font-medium text-white shadow-sm hover:bg-warning-500 focus:outline-none focus:ring-2 focus:ring-warning-500"
                                        >
                                            Confirm
                                        </button>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        wire:click="mountAction('saveReportPayment', { id: {{ $reportRow['id'] }} })"
                                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                    >
                                        Save
                                    </button>
                                    @if ($isOpen)
                                        <button
                                            type="button"
                                            wire:click="mountAction('payPayment', { id: {{ $reportRow['id'] }} })"
                                            class="inline-flex items-center rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
                                        >
                                            Payed
                                        </button>
                                    @elseif ($isPayed)
                                        <button
                                            type="button"
                                            wire:click="mountAction('cancelPayment', { id: {{ $reportRow['id'] }} })"
                                            class="inline-flex items-center rounded-lg border border-danger-300 bg-white px-3 py-1.5 text-sm font-medium text-danger-700 shadow-sm hover:bg-danger-50 focus:outline-none focus:ring-2 focus:ring-danger-500 dark:border-danger-500/40 dark:bg-gray-800 dark:text-danger-400 dark:hover:bg-gray-700"
                                        >
                                            Cancel
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        <tr wire:key="payment-report-tax-{{ $rowId }}" class="bg-gray-50/80 dark:bg-gray-800/40">
                            <td colspan="8" class="px-4 py-3">
                                @if ($hasTaxSnapshot)
                                    @php
                                        $taxCell = 'py-2 px-3';
                                        $taxZebra = 'bg-gray-100 dark:bg-gray-700/50';
                                        $taxPlain = 'bg-white dark:bg-gray-900';
                                    @endphp
                                    <div class="w-full">
                                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            Tax lines
                                        </p>

                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="min-w-0">
                                                <table class="w-full text-xs">
                                                    <tbody>
                                                        <tr class="{{ $taxPlain }}">
                                                            <td class="{{ $taxCell }} font-medium text-gray-800 dark:text-gray-200">Gross</td>
                                                            <td class="{{ $taxCell }} text-right font-semibold text-gray-900 dark:text-gray-100">{{ $money }}{{ $gross }}</td>
                                                        </tr>
                                                        <tr class="{{ $taxZebra }}">
                                                            <td class="{{ $taxCell }} text-gray-500 dark:text-gray-400">NPD</td>
                                                            <td class="{{ $taxCell }} text-right font-medium text-gray-900 dark:text-gray-100">
                                                                @if ($npd === '—') — @else {{ $money }}{{ $npd }} @endif
                                                            </td>
                                                        </tr>
                                                        <tr class="{{ $taxPlain }}">
                                                            <td class="{{ $taxCell }} text-gray-500 dark:text-gray-400">GPM 20%</td>
                                                            <td class="{{ $taxCell }} text-right font-medium text-gray-900 dark:text-gray-100">
                                                                @if ($gpm === '—') — @else {{ $money }}{{ $gpm }} @endif
                                                            </td>
                                                        </tr>
                                                        <tr class="{{ $taxZebra }}">
                                                            <td class="{{ $taxCell }} text-gray-500 dark:text-gray-400">Sodra health 6.98%</td>
                                                            <td class="{{ $taxCell }} text-right font-medium text-gray-900 dark:text-gray-100">
                                                                @if ($sodraHealth === '—') — @else {{ $money }}{{ $sodraHealth }} @endif
                                                            </td>
                                                        </tr>
                                                        <tr class="{{ $taxPlain }}">
                                                            <td class="{{ $taxCell }} text-gray-500 dark:text-gray-400">Sodra pension &amp; soc.</td>
                                                            <td class="{{ $taxCell }} text-right font-medium text-gray-900 dark:text-gray-100">
                                                                @if ($sodraPension === '—') — @else {{ $money }}{{ $sodraPension }} @endif
                                                            </td>
                                                        </tr>
                                                        <tr class="{{ $taxZebra }}">
                                                            <td class="{{ $taxCell }} font-medium text-gray-800 dark:text-gray-200">Net to employee</td>
                                                            <td class="{{ $taxCell }} text-right font-semibold text-success-700 dark:text-success-400">
                                                                @if ($net === '—') — @else {{ $money }}{{ $net }} @endif
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="min-w-0">
                                                <table class="w-full text-xs">
                                                    <tbody>
                                                        <tr class="{{ $taxPlain }}">
                                                            <td colspan="2" class="{{ $taxCell }} text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                                                Employer taxes
                                                            </td>
                                                        </tr>
                                                        <tr class="{{ $taxZebra }}">
                                                            <td class="{{ $taxCell }} text-gray-500 dark:text-gray-400">Sodra employer 1.77%</td>
                                                            <td class="{{ $taxCell }} text-right font-medium text-gray-900 dark:text-gray-100">
                                                                @if ($sodraEmployer === '—') — @else {{ $money }}{{ $sodraEmployer }} @endif
                                                            </td>
                                                        </tr>
                                                        <tr class="{{ $taxPlain }}">
                                                            <td class="{{ $taxCell }} font-medium text-gray-800 dark:text-gray-200">Total workplace cost</td>
                                                            <td class="{{ $taxCell }} text-right font-semibold text-primary-700 dark:text-primary-400">
                                                                @if ($workplaceCost === '—') — @else {{ $money }}{{ $workplaceCost }} @endif
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        Tax lines appear after Save (NPD, Sodra employee/employer, VMI GPM, net).
                                    </p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                No payment report data yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        @if (collect($reportRows)->where('status', 'open')->isNotEmpty())
            <button
                type="button"
                wire:click="mountAction('paySelected')"
                class="inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
                Payed
            </button>
            <button
                type="button"
                wire:click="exportSepaXml"
                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                Export SEPA (employees)
            </button>
        @endif

        @if (collect($reportRows)->contains(fn ($row) => in_array(($row['status'] ?? ''), ['open', 'payed'], true)))
            <button
                type="button"
                wire:click="exportVmiXml"
                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                Export VMI (GPM 1311)
            </button>
            <button
                type="button"
                wire:click="exportSodraXml"
                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                Export Sodra (252)
            </button>
        @endif

        @if (collect($reportRows)->isNotEmpty())
            <button
                type="button"
                wire:click="exportXls"
                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                Export to XLS
            </button>
        @endif

        @if (collect($reportRows)->contains(fn ($row) => empty($row['report_id'])))
            <button
                type="button"
                wire:click="mountAction('approveReport')"
                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                Create report
            </button>
        @endif
    </div>
</x-filament-panels::page>
