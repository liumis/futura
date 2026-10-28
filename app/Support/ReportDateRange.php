<?php

namespace App\Support;

use App\Services\WorkTimeTimesheetPdfGenerator;
use Illuminate\Support\Carbon;

class ReportDateRange
{
    public const MONTH_ANY = 'any';

    public const MONTH_THIS = 'this';

    public const MONTH_LAST = 'last';

    /**
     * @return array{from: ?string, until: ?string}
     */
    public static function boundsForMonthKey(string $monthKey): array
    {
        $monthKey = trim($monthKey);

        if ($monthKey === '' || $monthKey === self::MONTH_ANY) {
            return [
                'from' => null,
                'until' => null,
            ];
        }

        if ($monthKey === self::MONTH_THIS) {
            $monthKey = now()->format('Y-m');
        } elseif ($monthKey === self::MONTH_LAST) {
            $monthKey = now()->subMonthNoOverflow()->format('Y-m');
        }

        $start = Carbon::createFromFormat('Y-m', $monthKey)->startOfMonth();

        return [
            'from' => $start->toDateString(),
            'until' => $start->copy()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Return quick key (this/last) or Y-m when from/until are exactly one calendar month; otherwise null.
     */
    public static function monthKeyFromDates(mixed $from, mixed $until): ?string
    {
        if (blank($from) || blank($until)) {
            return blank($from) && blank($until) ? self::MONTH_ANY : null;
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

        $key = $fromDate->format('Y-m');
        $thisKey = now()->format('Y-m');
        $lastKey = now()->subMonthNoOverflow()->format('Y-m');

        if ($key === $thisKey) {
            return self::MONTH_THIS;
        }

        if ($key === $lastKey) {
            return self::MONTH_LAST;
        }

        return $key;
    }

    /**
     * All months / This / Last, then calendar months (newest first). Skips duplicate this/last labels.
     *
     * @return array<string, string>
     */
    public static function monthQuickSelectOptions(): array
    {
        $thisKey = now()->format('Y-m');
        $lastKey = now()->subMonthNoOverflow()->format('Y-m');

        $options = [
            self::MONTH_ANY => 'All months',
            self::MONTH_THIS => 'This month',
            self::MONTH_LAST => 'Last month',
        ];

        foreach (WorkTimeTimesheetPdfGenerator::monthSelectOptions() as $key => $label) {
            if ($key === $thisKey || $key === $lastKey) {
                continue;
            }
            $options[$key] = $label;
        }

        return $options;
    }

    /**
     * @return array{month: string, from: string, until: string}
     */
    public static function currentMonthFill(): array
    {
        return self::fillForMonthKey(self::MONTH_THIS);
    }

    /**
     * @return array{month: string, from: string, until: string}
     */
    public static function lastMonthFill(): array
    {
        return self::fillForMonthKey(self::MONTH_LAST);
    }

    /**
     * @return array{month: string, from: ?string, until: ?string}
     */
    public static function fillForMonthKey(string $monthKey): array
    {
        $bounds = self::boundsForMonthKey($monthKey);

        return [
            'month' => $monthKey,
            'from' => $bounds['from'],
            'until' => $bounds['until'],
        ];
    }
}
