<?php

namespace App\Services;

use App\Enums\EmployeeContractStatus;
use App\Enums\LeaveRequestStatus;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;

/**
 * Kasmetinės atostogos + papildomos atostogos už stažą (DK / Vyriausybės tvarka).
 *
 * Base: 20 working days / year (5-day week).
 * Seniority at the same employer:
 * - after 10 years continuous service → +3 working days
 * - for each further full 5 years → +1 working day
 */
final class AnnualLeaveCalculator
{
    public const DAYS_PER_YEAR = 20;

    public const SENIORITY_THRESHOLD_YEARS = 10;

    public const SENIORITY_BASE_EXTRA_DAYS = 3;

    public const SENIORITY_STEP_YEARS = 5;

    public const SENIORITY_STEP_EXTRA_DAYS = 1;

    public const ANNUAL_LEAVE_TYPE_NAME = 'Kasmetinės atostogos';

    /**
     * @return array{
     *     ok: bool,
     *     message: ?string,
     *     as_of: string,
     *     contract_start: ?string,
     *     employment_days: int,
     *     completed_years: int,
     *     base_days_per_year: int,
     *     seniority_extra_days: int,
     *     days_per_year: int,
     *     accrued_days: float,
     *     used_days: float,
     *     available_days: float,
     *     seniority_rule_label: string,
     * }
     */
    public static function calculate(Employee $employee, Carbon|string $asOf): array
    {
        $asOfDate = ($asOf instanceof Carbon ? $asOf->copy() : Carbon::parse((string) $asOf))->startOfDay();

        $contractStart = self::contractStartDate($employee);

        if ($contractStart === null) {
            return self::emptyResult(
                asOf: $asOfDate,
                message: 'No contract start date found. Add a non-draft employment contract with an effective from date.',
            );
        }

        if ($asOfDate->lt($contractStart)) {
            return self::emptyResult(
                asOf: $asOfDate,
                message: 'Selected date is before the contract start date ('.$contractStart->toDateString().').',
                contractStart: $contractStart,
            );
        }

        $employmentDays = $contractStart->diffInDays($asOfDate) + 1;
        $completedYears = self::completedYears($contractStart, $asOfDate);
        $seniorityExtra = self::seniorityExtraDays($completedYears);
        $daysPerYear = self::DAYS_PER_YEAR + $seniorityExtra;
        $accruedDays = self::accruedDays($contractStart, $asOfDate);
        $usedDays = self::usedAnnualLeaveWorkingDays($employee, $contractStart, $asOfDate);
        $availableDays = round($accruedDays - $usedDays, 2);

        return [
            'ok' => true,
            'message' => null,
            'as_of' => $asOfDate->toDateString(),
            'contract_start' => $contractStart->toDateString(),
            'employment_days' => $employmentDays,
            'completed_years' => $completedYears,
            'base_days_per_year' => self::DAYS_PER_YEAR,
            'seniority_extra_days' => $seniorityExtra,
            'days_per_year' => $daysPerYear,
            'accrued_days' => $accruedDays,
            'used_days' => $usedDays,
            'available_days' => $availableDays,
            'seniority_rule_label' => self::seniorityRuleLabel($seniorityExtra, $completedYears),
        ];
    }

    /**
     * Extra annual leave days for continuous service at the same employer.
     */
    public static function seniorityExtraDays(int $completedYears): int
    {
        if ($completedYears < self::SENIORITY_THRESHOLD_YEARS) {
            return 0;
        }

        $extraAfterThreshold = (int) floor(
            ($completedYears - self::SENIORITY_THRESHOLD_YEARS) / self::SENIORITY_STEP_YEARS,
        );

        return self::SENIORITY_BASE_EXTRA_DAYS + ($extraAfterThreshold * self::SENIORITY_STEP_EXTRA_DAYS);
    }

    public static function completedYears(Carbon $contractStart, Carbon $asOf): int
    {
        return max(0, (int) $contractStart->diffInYears($asOf));
    }

    public static function contractStartDate(Employee $employee): ?Carbon
    {
        $date = EmployeeContract::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', '!=', EmployeeContractStatus::Draft->value)
            ->whereNotNull('effective_date_from')
            ->orderBy('effective_date_from')
            ->value('effective_date_from');

        return filled($date) ? Carbon::parse((string) $date)->startOfDay() : null;
    }

    public static function usedAnnualLeaveWorkingDays(Employee $employee, Carbon $from, Carbon $to): float
    {
        $leaves = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [
                LeaveRequestStatus::Confirmed->value,
                LeaveRequestStatus::CancellationPending->value,
            ])
            ->whereHas('leaveRequestType', function ($query): void {
                $query->where('name', self::ANNUAL_LEAVE_TYPE_NAME);
            })
            ->whereDate('date_from', '<=', $to->toDateString())
            ->whereDate('date_to', '>=', $from->toDateString())
            ->get(['date_from', 'date_to']);

        $used = 0;

        foreach ($leaves as $leave) {
            $start = $leave->date_from->copy()->startOfDay()->max($from);
            $end = $leave->date_to->copy()->startOfDay()->min($to);

            if ($end->lt($start)) {
                continue;
            }

            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                if ($day->isWeekday()) {
                    $used++;
                }
            }
        }

        return (float) $used;
    }

    /**
     * Accrue day-by-day rate changes on hire anniversaries when seniority steps up.
     */
    protected static function accruedDays(Carbon $contractStart, Carbon $asOf): float
    {
        $total = 0.0;
        $segmentStart = $contractStart->copy()->startOfDay();

        while ($segmentStart->lte($asOf)) {
            $years = self::completedYears($contractStart, $segmentStart);
            $rate = self::DAYS_PER_YEAR + self::seniorityExtraDays($years);
            $nextAnniversary = $contractStart->copy()->addYears($years + 1)->startOfDay();
            $segmentEnd = $asOf->lt($nextAnniversary)
                ? $asOf->copy()
                : $nextAnniversary->copy()->subDay();

            if ($segmentEnd->lt($segmentStart)) {
                break;
            }

            $days = $segmentStart->diffInDays($segmentEnd) + 1;
            $total += ($days / 365) * $rate;
            $segmentStart = $segmentEnd->copy()->addDay();
        }

        return round($total, 2);
    }

    protected static function seniorityRuleLabel(int $seniorityExtra, int $completedYears): string
    {
        if ($seniorityExtra <= 0) {
            $yearsLeft = max(0, self::SENIORITY_THRESHOLD_YEARS - $completedYears);

            return $yearsLeft > 0
                ? "No seniority bonus yet ({$completedYears} y continuous). +3 days after 10 years ({$yearsLeft} y left)."
                : 'No seniority bonus yet.';
        }

        return sprintf(
            '+%d seniority day(s) for %d year(s) continuous service (10y→+3, then +1 each 5y).',
            $seniorityExtra,
            $completedYears,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function emptyResult(Carbon $asOf, string $message, ?Carbon $contractStart = null): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'as_of' => $asOf->toDateString(),
            'contract_start' => $contractStart?->toDateString(),
            'employment_days' => 0,
            'completed_years' => 0,
            'base_days_per_year' => self::DAYS_PER_YEAR,
            'seniority_extra_days' => 0,
            'days_per_year' => self::DAYS_PER_YEAR,
            'accrued_days' => 0.0,
            'used_days' => 0.0,
            'available_days' => 0.0,
            'seniority_rule_label' => 'No seniority bonus yet.',
        ];
    }
}
