<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class BusinessTripAllowanceCalculator
{
    public const SHORT_DAY_HOURS = 4.0;

    public const SHORT_DAY_FACTOR = 0.2;

    /**
     * @return array{
     *     calendar_days: int,
     *     payable_units: float,
     *     amount: float,
     *     first_day_factor: float,
     *     last_day_factor: float,
     *     middle_days: int
     * }
     */
    public static function calculate(
        float $dailyRate,
        CarbonInterface|string $dateFrom,
        CarbonInterface|string $dateTo,
        ?float $firstDayHours = null,
        ?float $lastDayHours = null,
    ): array {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->startOfDay();

        if ($to->lt($from)) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }

        if ($dailyRate < 0) {
            throw new InvalidArgumentException('Daily rate cannot be negative.');
        }

        $calendarDays = (int) $from->diffInDays($to) + 1;
        $firstFactor = self::dayFactor($firstDayHours);
        $lastFactor = self::dayFactor($lastDayHours);

        if ($calendarDays === 1) {
            // Single calendar day: only one payment applies (use first-day hours when set).
            $factor = self::dayFactor($firstDayHours ?? $lastDayHours);
            $payableUnits = $factor;

            return [
                'calendar_days' => 1,
                'payable_units' => round($payableUnits, 2),
                'amount' => round($dailyRate * $payableUnits, 2),
                'first_day_factor' => $factor,
                'last_day_factor' => $factor,
                'middle_days' => 0,
            ];
        }

        $middleDays = $calendarDays - 2;
        $payableUnits = $firstFactor + $lastFactor + $middleDays;

        return [
            'calendar_days' => $calendarDays,
            'payable_units' => round($payableUnits, 2),
            'amount' => round($dailyRate * $payableUnits, 2),
            'first_day_factor' => $firstFactor,
            'last_day_factor' => $lastFactor,
            'middle_days' => $middleDays,
        ];
    }

    public static function dayFactor(?float $hours): float
    {
        if ($hours === null) {
            return 1.0;
        }

        return $hours < self::SHORT_DAY_HOURS
            ? self::SHORT_DAY_FACTOR
            : 1.0;
    }
}
