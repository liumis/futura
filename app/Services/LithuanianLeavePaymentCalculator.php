<?php

namespace App\Services;

use App\Enums\EmployeeContractStatus;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeMonthlyPayment;
use App\Models\LeaveRequestType;
use App\Models\PayrollTaxSetting;
use App\Models\WorkScheduleEntry;
use Illuminate\Support\Carbon;

/**
 * Lithuanian leave payment (atostoginiai) — average wage (VDU) rules.
 *
 * Period: 3 calendar months before the month leave starts.
 * Daily VDU = period gross earnings / days actually worked.
 * Payment (gross) = daily VDU × working days of the leave.
 *
 * @see LR Vyriausybės nutarimas dėl VDU skaičiavimo tvarkos
 */
final class LithuanianLeavePaymentCalculator
{
    /** SADM-style average working days in a month (5-day week). */
    public const AVERAGE_MONTH_WORKING_DAYS = 20.9;

    /**
     * Leave types paid using average wage.
     *
     * @var list<string>
     */
    public const PAID_TYPES = [
        'Kasmetinės atostogos',
        'Tėvadienis / Mamadienis',
    ];

    /**
     * @return array{
     *     ok: bool,
     *     gross: float,
     *     message: string,
     *     daily_vdu: float,
     *     leave_working_days: int,
     *     period_from: ?string,
     *     period_to: ?string,
     *     period_earnings: float,
     *     period_worked_days: float,
     *     source: string,
     * }
     */
    public static function calculate(
        Employee $employee,
        Carbon|string $dateFrom,
        Carbon|string $dateTo,
        LeaveRequestType|string|null $type = null,
    ): array {
        $from = ($dateFrom instanceof Carbon ? $dateFrom->copy() : Carbon::parse((string) $dateFrom))->startOfDay();
        $to = ($dateTo instanceof Carbon ? $dateTo->copy() : Carbon::parse((string) $dateTo))->startOfDay();

        if ($to->lt($from)) {
            return self::result(
                ok: false,
                gross: 0,
                message: 'Date to must be on or after date from.',
            );
        }

        $typeName = self::typeName($type);

        if ($typeName !== null && ! self::isPaidType($typeName)) {
            return self::result(
                ok: true,
                gross: 0,
                message: 'This leave type is not paid by average wage (payment = 0).',
                leaveWorkingDays: self::countWeekdays($from, $to),
                source: 'unpaid_type',
            );
        }

        $leaveDays = self::countWeekdays($from, $to);

        if ($leaveDays <= 0) {
            return self::result(
                ok: true,
                gross: 0,
                message: 'No working days in the selected leave period.',
                leaveWorkingDays: 0,
                source: 'no_leave_days',
            );
        }

        $periodEnd = $from->copy()->startOfMonth()->subDay()->endOfDay();
        $periodStart = $from->copy()->startOfMonth()->subMonths(3)->startOfDay();

        $earnings = self::periodEarnings($employee, $periodStart, $periodEnd);
        $workedDays = self::periodWorkedDays($employee, $periodStart, $periodEnd);

        $source = 'vdu_payments';
        $daily = 0.0;

        if ($earnings > 0 && $workedDays > 0) {
            $daily = $earnings / $workedDays;
        } else {
            $fallback = self::contractDailyRate($employee, $from);
            if ($fallback === null) {
                return self::result(
                    ok: false,
                    gross: 0,
                    message: 'Not enough payroll data for the 3 months before leave, and no contract base salary to fall back to.',
                    leaveWorkingDays: $leaveDays,
                    periodFrom: $periodStart->toDateString(),
                    periodTo: $periodEnd->toDateString(),
                    periodEarnings: $earnings,
                    periodWorkedDays: $workedDays,
                    source: 'insufficient_data',
                );
            }

            $daily = $fallback;
            $source = $earnings > 0 || $workedDays > 0
                ? 'mixed_fallback_contract'
                : 'contract_fallback';
        }

        $daily = self::applyMinimumDailyRate($daily, $from);
        $gross = round($daily * $leaveDays, 2);

        return self::result(
            ok: true,
            gross: $gross,
            message: sprintf(
                'VDU %s €/day × %d working day(s) = %s € (period %s – %s, earnings %s €, worked days %s).',
                number_format($daily, 2, '.', ''),
                $leaveDays,
                number_format($gross, 2, '.', ''),
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
                number_format($earnings, 2, '.', ''),
                number_format($workedDays, 2, '.', ''),
            ),
            dailyVdu: round($daily, 4),
            leaveWorkingDays: $leaveDays,
            periodFrom: $periodStart->toDateString(),
            periodTo: $periodEnd->toDateString(),
            periodEarnings: round($earnings, 2),
            periodWorkedDays: round($workedDays, 2),
            source: $source,
        );
    }

    public static function isPaidType(string $typeName): bool
    {
        return in_array($typeName, self::PAID_TYPES, true);
    }

    protected static function typeName(LeaveRequestType|string|null $type): ?string
    {
        if ($type instanceof LeaveRequestType) {
            return $type->name;
        }

        if (is_string($type) && $type !== '') {
            return $type;
        }

        return null;
    }

    protected static function countWeekdays(Carbon $from, Carbon $to): int
    {
        $count = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if ($day->isWeekday()) {
                $count++;
            }
        }

        return $count;
    }

    protected static function periodEarnings(Employee $employee, Carbon $periodStart, Carbon $periodEnd): float
    {
        $payments = EmployeeMonthlyPayment::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('payment_date', '>=', $periodStart->toDateString())
            ->whereDate('payment_date', '<=', $periodEnd->toDateString())
            ->get(['gross_amount', 'base_salary', 'bonus_payment']);

        $total = 0.0;

        foreach ($payments as $payment) {
            if ($payment->gross_amount !== null) {
                $total += (float) $payment->gross_amount;
            } else {
                $total += (float) ($payment->base_salary ?? 0) + (float) ($payment->bonus_payment ?? 0);
            }
        }

        return $total;
    }

    protected static function periodWorkedDays(Employee $employee, Carbon $periodStart, Carbon $periodEnd): float
    {
        $entries = WorkScheduleEntry::query()
            ->whereHas('workSchedule', fn ($query) => $query->where('employee_id', $employee->getKey()))
            ->whereDate('work_date', '>=', $periodStart->toDateString())
            ->whereDate('work_date', '<=', $periodEnd->toDateString())
            ->get(['hours', 'actual_hours', 'is_not_working_day']);

        if ($entries->isEmpty()) {
            // No timetable: approximate with weekdays in months that have a payroll row.
            $monthsWithPay = EmployeeMonthlyPayment::query()
                ->where('employee_id', $employee->getKey())
                ->whereDate('payment_date', '>=', $periodStart->toDateString())
                ->whereDate('payment_date', '<=', $periodEnd->toDateString())
                ->get(['payment_date']);

            if ($monthsWithPay->isEmpty()) {
                return 0.0;
            }

            $days = 0;
            foreach ($monthsWithPay as $payment) {
                $monthStart = $payment->payment_date->copy()->startOfMonth()->max($periodStart);
                $monthEnd = $payment->payment_date->copy()->endOfMonth()->min($periodEnd);
                $days += self::countWeekdays($monthStart, $monthEnd);
            }

            return (float) $days;
        }

        $worked = 0.0;

        foreach ($entries as $entry) {
            if ($entry->is_not_working_day) {
                continue;
            }

            $hours = $entry->actual_hours !== null
                ? (float) $entry->actual_hours
                : (float) ($entry->hours ?? 0);

            if ($hours > 0) {
                $worked++;
            }
        }

        return $worked;
    }

    protected static function contractDailyRate(Employee $employee, Carbon $asOf): ?float
    {
        $contract = EmployeeContract::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', '!=', EmployeeContractStatus::Draft->value)
            ->whereDate('effective_date_from', '<=', $asOf->toDateString())
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $asOf->toDateString());
            })
            ->orderByDesc('effective_date_from')
            ->first();

        if ($contract === null) {
            $contract = EmployeeContract::query()
                ->where('employee_id', $employee->getKey())
                ->where('status', '!=', EmployeeContractStatus::Draft->value)
                ->orderByDesc('effective_date_from')
                ->first();
        }

        $base = (float) ($contract?->base_salary ?? 0);

        if ($base <= 0) {
            return null;
        }

        return $base / self::AVERAGE_MONTH_WORKING_DAYS;
    }

    protected static function applyMinimumDailyRate(float $daily, Carbon $leaveStart): float
    {
        $settings = PayrollTaxSetting::forYear((int) $leaveStart->year);
        $mma = (float) ($settings->mma ?? 0);

        if ($mma <= 0) {
            return $daily;
        }

        $monthStart = $leaveStart->copy()->startOfMonth();
        $monthEnd = $leaveStart->copy()->endOfMonth();
        $workingDaysInMonth = self::countWeekdays($monthStart, $monthEnd);

        if ($workingDaysInMonth <= 0) {
            return $daily;
        }

        $minimumDaily = $mma / $workingDaysInMonth;

        return max($daily, $minimumDaily);
    }

    /**
     * @return array{
     *     ok: bool,
     *     gross: float,
     *     message: string,
     *     daily_vdu: float,
     *     leave_working_days: int,
     *     period_from: ?string,
     *     period_to: ?string,
     *     period_earnings: float,
     *     period_worked_days: float,
     *     source: string,
     * }
     */
    protected static function result(
        bool $ok,
        float $gross,
        string $message,
        float $dailyVdu = 0.0,
        int $leaveWorkingDays = 0,
        ?string $periodFrom = null,
        ?string $periodTo = null,
        float $periodEarnings = 0.0,
        float $periodWorkedDays = 0.0,
        string $source = 'none',
    ): array {
        return [
            'ok' => $ok,
            'gross' => round($gross, 2),
            'message' => $message,
            'daily_vdu' => $dailyVdu,
            'leave_working_days' => $leaveWorkingDays,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'period_earnings' => $periodEarnings,
            'period_worked_days' => $periodWorkedDays,
            'source' => $source,
        ];
    }
}
