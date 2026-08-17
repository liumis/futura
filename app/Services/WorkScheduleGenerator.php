<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LtHoliday;
use Illuminate\Support\Carbon;

class WorkScheduleGenerator
{
    public const BASE_HOURS_PER_DAY = 8;

    public static function defaultHoursFor(Employee $employee): float
    {
        $percentage = (float) ($employee->working_time_percentage ?? 100);

        return round(self::BASE_HOURS_PER_DAY * $percentage / 100, 2);
    }

    /**
     * @return list<array{work_date: string, hours: float, actual_hours: float, is_not_working_day: bool}>
     */
    public static function forEmployeeMonth(Employee $employee, int $year, int $month): array
    {
        $defaultHours = self::defaultHoursFor($employee);
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $holidayDates = LtHoliday::datesBetween($start, $end);
        $entries = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dateKey = $date->toDateString();
            $isNotWorkingDay = $date->isWeekend() || array_key_exists($dateKey, $holidayDates);
            $hours = $isNotWorkingDay ? 0.0 : $defaultHours;

            $entries[] = [
                'work_date' => $dateKey,
                'hours' => $hours,
                'actual_hours' => $hours,
                'is_not_working_day' => $isNotWorkingDay,
            ];
        }

        return $entries;
    }
}
