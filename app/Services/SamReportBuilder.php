<?php

namespace App\Services;

use App\Enums\EmployeeMonthlyPaymentStatus;
use App\Models\CompanySetting;
use App\Models\EmployeeMonthlyPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SamReportBuilder
{
    /**
     * @return array{from: Carbon, until: Carbon}
     */
    public static function period(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();

        return [
            'from' => $from,
            'until' => $from->copy()->endOfMonth()->endOfDay(),
        ];
    }

    /**
     * @return Collection<int, EmployeeMonthlyPayment>
     */
    public static function payments(int $year, int $month): Collection
    {
        $period = self::period($year, $month);

        return EmployeeMonthlyPayment::query()
            ->with('employee')
            ->whereDate('payment_date', '>=', $period['from']->toDateString())
            ->whereDate('payment_date', '<=', $period['until']->toDateString())
            ->whereIn('status', [
                EmployeeMonthlyPaymentStatus::Open->value,
                EmployeeMonthlyPaymentStatus::Payed->value,
            ])
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *     year: int,
     *     month: int,
     *     period_key: string,
     *     period_label: string,
     *     month_name: string,
     *     company_name: string,
     *     company_id: string,
     *     company_address: string,
     *     company_email: string,
     *     company_phone: string,
     *     payment_code: string,
     *     sodra_employee_total: float,
     *     sodra_employer_total: float,
     *     sodra_total: float,
     *     sodra_employee_label: string,
     *     sodra_employer_label: string,
     *     sodra_total_label: string,
     *     gross_total: float,
     *     gross_total_label: string,
     *     rows: list<array{
     *         employee_name: string,
     *         personal_code: string,
     *         gross: float,
     *         sodra_employee: float,
     *         sodra_employer: float,
     *         sodra_total: float,
     *         gross_label: string,
     *         sodra_employee_label: string,
     *         sodra_employer_label: string,
     *         sodra_total_label: string
     *     }>,
     *     employee_count: int
     * }
     */
    public static function build(int $year, int $month): array
    {
        $company = CompanySetting::instance();
        $payments = self::payments($year, $month);
        $monthName = WorkTimeTimesheetPdfGenerator::MONTH_NAMES_LT[$month] ?? (string) $month;

        $rows = [];
        foreach ($payments as $payment) {
            $employee = $payment->employee;
            $ee = round((float) ($payment->sodra_employee_amount ?? 0), 2);
            $er = round((float) ($payment->sodra_employer_amount ?? 0), 2);
            $gross = round((float) ($payment->gross_amount ?? 0), 2);
            $total = round($ee + $er, 2);

            $rows[] = [
                'employee_name' => $employee?->fullName() ?: ('#'.$payment->employee_id),
                'personal_code' => '',
                'gross' => $gross,
                'sodra_employee' => $ee,
                'sodra_employer' => $er,
                'sodra_total' => $total,
                'gross_label' => self::formatLt($gross),
                'sodra_employee_label' => self::formatLt($ee),
                'sodra_employer_label' => self::formatLt($er),
                'sodra_total_label' => self::formatLt($total),
            ];
        }

        $sodraEmployee = round(array_sum(array_column($rows, 'sodra_employee')), 2);
        $sodraEmployer = round(array_sum(array_column($rows, 'sodra_employer')), 2);
        $sodraTotal = round($sodraEmployee + $sodraEmployer, 2);
        $grossTotal = round(array_sum(array_column($rows, 'gross')), 2);

        return [
            'year' => $year,
            'month' => $month,
            'period_key' => sprintf('%04d-%02d', $year, $month),
            'period_label' => $year.' '.$monthName,
            'month_name' => $monthName,
            'company_name' => trim((string) ($company->company_name ?: 'Įmonė')),
            'company_id' => trim((string) ($company->company_id ?? '')),
            'company_address' => trim((string) ($company->company_address ?? '')),
            'company_email' => trim((string) ($company->company_email ?? '')),
            'company_phone' => trim((string) ($company->company_phone ?? '')),
            'payment_code' => PayrollAuthoritySepaExporter::SODRA_PAYMENT_CODE,
            'sodra_employee_total' => $sodraEmployee,
            'sodra_employer_total' => $sodraEmployer,
            'sodra_total' => $sodraTotal,
            'sodra_employee_label' => self::formatLt($sodraEmployee),
            'sodra_employer_label' => self::formatLt($sodraEmployer),
            'sodra_total_label' => self::formatLt($sodraTotal),
            'gross_total' => $grossTotal,
            'gross_total_label' => self::formatLt($grossTotal),
            'rows' => $rows,
            'employee_count' => count($rows),
        ];
    }

    public static function formatLt(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }

    public static function monthSodraTotal(int $year, int $month): float
    {
        $period = self::period($year, $month);
        $payments = EmployeeMonthlyPayment::query()
            ->whereDate('payment_date', '>=', $period['from']->toDateString())
            ->whereDate('payment_date', '<=', $period['until']->toDateString())
            ->get(['sodra_employee_amount', 'sodra_employer_amount']);

        return round(
            (float) $payments->sum('sodra_employee_amount') + (float) $payments->sum('sodra_employer_amount'),
            2,
        );
    }
}
