<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeMonthlyPayment;
use App\Models\PayrollTaxSetting;
use App\Models\WorkSchedule;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PayrollAccrualJournalPdfGenerator
{
    /** @var array<int, string> */
    public const MONTH_NAMES_GENITIVE_LT = [
        1 => 'sausio',
        2 => 'vasario',
        3 => 'kovo',
        4 => 'balandžio',
        5 => 'gegužės',
        6 => 'birželio',
        7 => 'liepos',
        8 => 'rugpjūčio',
        9 => 'rugsėjo',
        10 => 'spalio',
        11 => 'lapkričio',
        12 => 'gruodio',
    ];

    /**
     * @param  Collection<int, EmployeeMonthlyPayment>|list<EmployeeMonthlyPayment>  $payments
     */
    public static function generate(Collection|array $payments, ?int $year = null, ?int $month = null): string
    {
        $payload = self::build($payments, $year, $month);

        return Pdf::loadView('pdf.payroll-accrual-journal', $payload)
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /**
     * @param  Collection<int, EmployeeMonthlyPayment>|list<EmployeeMonthlyPayment>  $payments
     * @return array<string, mixed>
     */
    public static function build(Collection|array $payments, ?int $year = null, ?int $month = null): array
    {
        $payments = collect($payments)
            ->filter(fn ($payment): bool => $payment instanceof EmployeeMonthlyPayment)
            ->sortBy(fn (EmployeeMonthlyPayment $payment): string => mb_strtolower($payment->employee?->fullName() ?? ''))
            ->values();

        if ($payments->isEmpty()) {
            throw new \InvalidArgumentException('No payments to export.');
        }

        $firstDate = Carbon::parse((string) $payments->first()->payment_date);
        $year ??= (int) $firstDate->year;
        $month ??= (int) $firstDate->month;

        $company = CompanySetting::instance();
        $taxSettings = PayrollTaxSetting::forYear($year);
        $calculator = app(LithuanianPayrollCalculator::class);

        $rows = [];
        $totals = self::emptyTotals();

        foreach ($payments as $index => $payment) {
            $employee = $payment->employee;
            if (! $employee instanceof Employee) {
                continue;
            }

            $paymentDate = Carbon::parse((string) $payment->payment_date);
            $rowYear = (int) $paymentDate->year;
            $rowMonth = (int) $paymentDate->month;

            $schedule = WorkSchedule::monthSummaryForEmployee(
                (int) $employee->getKey(),
                $rowYear,
                $rowMonth,
            );

            $contract = EmployeeContract::query()
                ->validDuringMonth($paymentDate->copy()->startOfMonth())
                ->where('employee_id', $employee->getKey())
                ->orderByDesc('effective_date_from')
                ->first();

            $contractBase = (float) ($contract?->base_salary ?? $payment->base_salary ?? 0);
            $base = (float) $payment->base_salary;
            $bonus = $payment->bonus_payment !== null ? (float) $payment->bonus_payment : 0.0;
            $gross = $payment->gross_amount !== null
                ? (float) $payment->gross_amount
                : round($base + $bonus, 2);

            $fixedTerm = filled($contract?->valid_to);
            $tax = $payment->gross_amount !== null
                ? [
                    'gross' => $gross,
                    'npd' => (float) ($payment->npd_amount ?? 0),
                    'gpm' => (float) ($payment->gpm_amount ?? 0),
                    'sodra_employee' => (float) ($payment->sodra_employee_amount ?? 0),
                    'sodra_employer' => (float) ($payment->sodra_employer_amount ?? 0),
                    'sodra_health' => round($gross * 0.0698, 2),
                    'sodra_pension' => 0.0,
                    'employer_sodra_rate' => $fixedTerm
                        ? (float) ($taxSettings->employer_sodra_fixed_term_rate ?? 0.0249)
                        : (float) ($taxSettings->employer_sodra_permanent_rate ?? 0.0177),
                ]
                : $calculator->calculate(
                    gross: $gross,
                    employeeOrNpdType: $employee,
                    fixedTermContract: $fixedTerm,
                    year: $rowYear,
                );

            if ($payment->gross_amount !== null) {
                $tax['sodra_health'] = round($gross * 0.0698, 2);
                $tax['sodra_pension'] = round(max(0, $tax['sodra_employee'] - $tax['sodra_health']), 2);
            }

            $pillarRate = $employee->second_pillar_enrolled
                ? (float) ($employee->second_pillar_rate ?? 0.03)
                : 0.0;
            $secondPillar = round($gross * $pillarRate, 2);
            $vsdEmployeeBase = round(max(0, $tax['sodra_pension'] - $secondPillar), 2);
            $deductionsTotal = round($tax['gpm'] + $tax['sodra_employee'], 2);
            $net = $payment->net_amount !== null
                ? (float) $payment->net_amount
                : round(max(0, $gross - $deductionsTotal), 2);
            $employerVsd249 = $fixedTerm ? $tax['sodra_employer'] : 0.0;
            $employerVsd177 = $fixedTerm ? 0.0 : $tax['sodra_employer'];

            $plannedHours = (float) ($schedule['planned_hours'] ?? 0);
            $actualHours = (float) ($schedule['actual_hours'] ?? 0);
            $absenceHours = $schedule['has_schedule'] && $plannedHours > 0
                ? max(0, round($plannedHours - $actualHours, 2))
                : 0.0;

            $workDaysPay = $base;
            $row = [
                'row_number' => count($rows) + 1,
                'worked_days' => self::fmtHours($schedule['actual_working_days'] ?? 0, integer: true),
                'worked_hours' => self::fmtHours($actualHours),
                'rest_holiday_hours' => '',
                'absence_hours' => self::fmtHours($absenceHours),
                'paper_salary' => self::fmtMoney($contractBase),
                'npd' => self::fmtMoney($tax['npd']),
                'name' => mb_strtoupper($employee->name ?? ''),
                'surname' => mb_strtoupper($employee->surname ?? ''),
                'position' => (string) ($employee->position ?? ''),
                'sick_pay_company' => '',
                'accrued_work_days' => self::fmtMoney($workDaysPay),
                'holiday_work_pay' => '',
                'bonus' => self::fmtMoney($bonus),
                'rest_days_avg_pay' => '',
                'vacation_compensation' => '',
                'accruals_total' => self::fmtMoney($gross),
                'employer_vsd_249' => self::fmtMoney($employerVsd249),
                'employer_vsd_177' => self::fmtMoney($employerVsd177),
                'gpm' => self::fmtMoney($tax['gpm']),
                'vsd_employee' => self::fmtMoney($vsdEmployeeBase),
                'psd' => self::fmtMoney($tax['sodra_health']),
                'second_pillar' => self::fmtMoney($secondPillar),
                'deductions_total' => self::fmtMoney($deductionsTotal),
                'net' => self::fmtMoney($net),
            ];

            $rows[] = $row;

            self::accumulateTotals($totals, [
                'worked_days' => (float) ($schedule['actual_working_days'] ?? 0),
                'worked_hours' => $actualHours,
                'absence_hours' => $absenceHours,
                'paper_salary' => $contractBase,
                'npd' => $tax['npd'],
                'sick_pay_company' => 0.0,
                'accrued_work_days' => $workDaysPay,
                'bonus' => $bonus,
                'accruals_total' => $gross,
                'employer_vsd_249' => $employerVsd249,
                'employer_vsd_177' => $employerVsd177,
                'gpm' => $tax['gpm'],
                'vsd_employee' => $vsdEmployeeBase,
                'psd' => $tax['sodra_health'],
                'second_pillar' => $secondPillar,
                'deductions_total' => $deductionsTotal,
                'net' => $net,
            ]);
        }

        $totalsFormatted = [
            'worked_days' => self::fmtHours($totals['worked_days'], integer: true),
            'worked_hours' => self::fmtHours($totals['worked_hours']),
            'absence_hours' => self::fmtHours($totals['absence_hours']),
            'paper_salary' => self::fmtMoney($totals['paper_salary']),
            'npd' => self::fmtMoney($totals['npd']),
            'sick_pay_company' => self::fmtMoney($totals['sick_pay_company']),
            'accrued_work_days' => self::fmtMoney($totals['accrued_work_days']),
            'bonus' => self::fmtMoney($totals['bonus']),
            'accruals_total' => self::fmtMoney($totals['accruals_total']),
            'employer_vsd_249' => self::fmtMoney($totals['employer_vsd_249']),
            'employer_vsd_177' => self::fmtMoney($totals['employer_vsd_177']),
            'gpm' => self::fmtMoney($totals['gpm']),
            'vsd_employee' => self::fmtMoney($totals['vsd_employee']),
            'psd' => self::fmtMoney($totals['psd']),
            'second_pillar' => self::fmtMoney($totals['second_pillar']),
            'deductions_total' => self::fmtMoney($totals['deductions_total']),
            'net' => self::fmtMoney($totals['net']),
        ];

        return [
            'companyName' => filled($company->company_name) ? (string) $company->company_name : 'UAB',
            'companyId' => filled($company->company_id) ? (string) $company->company_id : null,
            'companyAddress' => filled($company->company_address) ? (string) $company->company_address : null,
            'companyEmail' => filled($company->company_email) ? (string) $company->company_email : null,
            'companyPhone' => filled($company->company_phone) ? (string) $company->company_phone : null,
            'logoDataUri' => self::logoDataUri(),
            'year' => $year,
            'month' => $month,
            'monthNameGenitive' => self::MONTH_NAMES_GENITIVE_LT[$month] ?? '',
            'documentNumber' => $month.' / '.$year,
            'rows' => $rows,
            'totals' => $totalsFormatted,
            'gpmRateLabel' => number_format(((float) $taxSettings->gpm_rate) * 100, 2, '.', '').'%',
            'psdRateLabel' => '6,98%',
            'vsdEmployeeRateLabel' => '12,52%',
            'secondPillarRateLabel' => '3,00%',
            'directorLabel' => filled($company->contact_name) ? (string) $company->contact_name : '',
        ];
    }

    /**
     * @return array<string, float>
     */
    protected static function emptyTotals(): array
    {
        return [
            'worked_days' => 0.0,
            'worked_hours' => 0.0,
            'absence_hours' => 0.0,
            'paper_salary' => 0.0,
            'npd' => 0.0,
            'sick_pay_company' => 0.0,
            'accrued_work_days' => 0.0,
            'bonus' => 0.0,
            'accruals_total' => 0.0,
            'employer_vsd_249' => 0.0,
            'employer_vsd_177' => 0.0,
            'gpm' => 0.0,
            'vsd_employee' => 0.0,
            'psd' => 0.0,
            'second_pillar' => 0.0,
            'deductions_total' => 0.0,
            'net' => 0.0,
        ];
    }

    /**
     * @param  array<string, float>  $totals
     * @param  array<string, float>  $row
     */
    protected static function accumulateTotals(array &$totals, array $row): void
    {
        foreach ($row as $key => $value) {
            $totals[$key] = ($totals[$key] ?? 0) + (float) $value;
        }
    }

    protected static function fmtMoney(float $amount): string
    {
        if (abs($amount) < 0.005) {
            return '';
        }

        return number_format($amount, 2, '.', '');
    }

    protected static function fmtHours(float|int $value, bool $integer = false): string
    {
        if ((float) $value <= 0) {
            return '';
        }

        if ($integer || abs((float) $value - round((float) $value)) < 0.001) {
            return (string) (int) round((float) $value);
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private static function logoDataUri(): ?string
    {
        $pngPath = public_path('images/logo-invoice.png');

        if (is_readable($pngPath)) {
            $contents = file_get_contents($pngPath);

            if ($contents !== false) {
                return 'data:image/png;base64,'.base64_encode($contents);
            }
        }

        $svgPath = public_path('images/logo.svg');

        if (! is_readable($svgPath)) {
            return null;
        }

        $contents = file_get_contents($svgPath);

        return $contents !== false
            ? 'data:image/svg+xml;base64,'.base64_encode($contents)
            : null;
    }
}
