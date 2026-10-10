<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class ReportDateRange
{
    /**
     * @return array{from: string, until: string}
     */
    public static function boundsForMonthKey(string $monthKey): array
    {
        $start = Carbon::createFromFormat('Y-m', $monthKey)->startOfMonth();

        return [
            'from' => $start->toDateString(),
            'until' => $start->copy()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Return Y-m when from/until are exactly one calendar month; otherwise null (custom range).
     */
    public static function monthKeyFromDates(mixed $from, mixed $until): ?string
    {
        if (blank($from) || blank($until)) {
            return null;
        }

        try {
            $fromDate = Carbon::parse((string) $from)->startOfDay();
            $untilDate = Carbon::parse((string) $until)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        if (! $fromDate->isSameMonth($untilDate)) {
            return null;
        }

        if (! $fromDate->equalTo($fromDate->copy()->startOfMonth())) {
            return null;
        }

        if (! $untilDate->equalTo($untilDate->copy()->endOfMonth()->startOfDay())) {
            return null;
        }

        return $fromDate->format('Y-m');
    }

    /**
     * @return array{month: string, from: string, until: string}
     */
    public static function currentMonthFill(): array
    {
        $month = now()->format('Y-m');
        $bounds = self::boundsForMonthKey($month);

        return [
            'month' => $month,
            'from' => $bounds['from'],
            'until' => $bounds['until'],
        ];
    }
}
