<?php

namespace App\Services;

use App\Enums\EmployeeContractStatus;
use App\Enums\LeaveRequestStatus;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\LeaveRequest;
use Illuminate\Support\Carbon;

final class AnnualLeaveCalculator
{
    public const DAYS_PER_YEAR = 20;

    public const ANNUAL_LEAVE_TYPE_NAME = 'Kasmetinės atostogos';

    /**
     * @return array{
     *     ok: bool,
     *     message: ?string,
     *     as_of: string,
     *     contract_start: ?string,
     *     employment_days: int,
     *     accrued_days: float,
     *     used_days: float,
     *     available_days: float,
     *     days_per_year: int,
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
        $accruedDays = round(($employmentDays / 365) * self::DAYS_PER_YEAR, 2);
        $usedDays = self::usedAnnualLeaveWorkingDays($employee, $contractStart, $asOfDate);
        $availableDays = round($accruedDays - $usedDays, 2);

        return [
            'ok' => true,
            'message' => null,
            'as_of' => $asOfDate->toDateString(),
            'contract_start' => $contractStart->toDateString(),
            'employment_days' => $employmentDays,
            'accrued_days' => $accruedDays,
            'used_days' => $usedDays,
            'available_days' => $availableDays,
            'days_per_year' => self::DAYS_PER_YEAR,
        ];
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
     * @return array{
     *     ok: bool,
     *     message: ?string,
     *     as_of: string,
     *     contract_start: ?string,
     *     employment_days: int,
     *     accrued_days: float,
     *     used_days: float,
     *     available_days: float,
     *     days_per_year: int,
     * }
     */
    protected static function emptyResult(Carbon $asOf, string $message, ?Carbon $contractStart = null): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'as_of' => $asOf->toDateString(),
            'contract_start' => $contractStart?->toDateString(),
            'employment_days' => 0,
            'accrued_days' => 0.0,
            'used_days' => 0.0,
            'available_days' => 0.0,
            'days_per_year' => self::DAYS_PER_YEAR,
        ];
    }
}
