<?php

namespace App\Services;

use App\Enums\DividendPaymentStatus;
use App\Enums\EmployeeMonthlyPaymentStatus;
use App\Models\CompanySetting;
use App\Models\Dividend;
use App\Models\EmployeeMonthlyPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Gpm313ReportBuilder
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
    public static function employmentPayments(int $year, int $month): Collection
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
     * @return Collection<int, Dividend>
     */
    public static function dividends(int $year, int $month): Collection
    {
        $period = self::period($year, $month);

        return Dividend::query()
            ->with('shareholder')
            ->whereDate('date', '>=', $period['from']->toDateString())
            ->whereDate('date', '<=', $period['until']->toDateString())
            ->whereIn('status', [
                DividendPaymentStatus::Open->value,
                DividendPaymentStatus::Payed->value,
            ])
            ->orderBy('date')
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
     *     field5: float,
     *     field6: float,
     *     field7: float,
     *     field8: float,
     *     field9: float,
     *     field10: float,
     *     field11: float,
     *     field12: float,
     *     field5_label: string,
     *     field6_label: string,
     *     field7_label: string,
     *     field8_label: string,
     *     field9_label: string,
     *     field10_label: string,
     *     field11_label: string,
     *     field12_label: string,
     *     employment_count: int,
     *     dividend_count: int
     * }
     */
    public static function build(int $year, int $month): array
    {
        $company = CompanySetting::instance();
        $employment = self::employmentPayments($year, $month);
        $dividends = self::dividends($year, $month);

        $until15 = $employment->filter(
            fn (EmployeeMonthlyPayment $payment): bool => (int) $payment->payment_date?->day <= 15,
        );
        $from16 = $employment->filter(
            fn (EmployeeMonthlyPayment $payment): bool => (int) $payment->payment_date?->day >= 16,
        );

        $field5 = self::sumAmount($employment, 'gross_amount');
        $field6 = self::sumAmount($until15, 'gpm_amount');
        $field7 = self::sumAmount($from16, 'gpm_amount');
        $field11 = self::sumAmount($dividends, 'amount');
        $field12 = self::sumAmount($dividends, 'gpm_amount');

        $monthName = WorkTimeTimesheetPdfGenerator::MONTH_NAMES_LT[$month] ?? (string) $month;

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
            'field5' => $field5,
            'field6' => $field6,
            'field7' => $field7,
            'field8' => 0.0,
            'field9' => 0.0,
            'field10' => 0.0,
            'field11' => $field11,
            'field12' => $field12,
            'field5_label' => self::formatLt($field5),
            'field6_label' => self::formatLt($field6),
            'field7_label' => self::formatLt($field7),
            'field8_label' => self::formatLt(0),
            'field9_label' => self::formatLt(0),
            'field10_label' => self::formatLt(0),
            'field11_label' => self::formatLt($field11),
            'field12_label' => self::formatLt($field12),
            'employment_count' => $employment->count(),
            'dividend_count' => $dividends->count(),
        ];
    }

    public static function formatLt(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }

    /**
     * @param  Collection<int, EmployeeMonthlyPayment|Dividend>  $rows
     */
    private static function sumAmount(Collection $rows, string $column): float
    {
        return round($rows->sum(fn ($row): float => (float) ($row->{$column} ?? 0)), 2);
    }
}
