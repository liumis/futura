<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class TodoRecurrence
{
    public const MAX_OCCURRENCES = 100;

    /**
     * @param  array{
     *     interval: int,
     *     unit: string,
     *     weekdays?: array<int, int|string>,
     *     ends: string,
     *     ends_on?: mixed,
     *     occurrences?: int
     * }  $config
     * @return list<array{start: Carbon, deadline: Carbon}>
     */
    public static function expand(
        Carbon $start,
        Carbon $deadline,
        array $config,
    ): array {
        $interval = max(1, (int) ($config['interval'] ?? 1));
        $unit = (string) ($config['unit'] ?? 'week');
        $ends = (string) ($config['ends'] ?? 'after');
        $maxCount = min(self::MAX_OCCURRENCES, max(1, (int) ($config['occurrences'] ?? 13)));
        $endsOn = filled($config['ends_on'] ?? null)
            ? Carbon::parse($config['ends_on'])->endOfDay()
            : null;

        if (! in_array($unit, ['day', 'week', 'month', 'year'], true)) {
            throw new InvalidArgumentException('Invalid recurrence unit.');
        }

        if ($ends === 'on' && $endsOn === null) {
            throw new InvalidArgumentException('Recurrence end date is required.');
        }

        if ($ends === 'after') {
            $endsOn = null;
        } else {
            $maxCount = self::MAX_OCCURRENCES;
        }

        $durationSeconds = $start->diffInSeconds($deadline, false);
        if ($durationSeconds < 0) {
            $durationSeconds = 3600;
        }

        $weekdays = collect($config['weekdays'] ?? [])
            ->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($unit === 'week' && $weekdays === []) {
            $weekdays = [$start->dayOfWeekIso];
        }

        $occurrences = [];

        if ($unit === 'week') {
            $cursor = $start->copy()->startOfDay();
            $startWeek = $start->copy()->startOfWeek(Carbon::MONDAY);
            $safety = 0;

            while (count($occurrences) < $maxCount && $safety < 2000) {
                $safety++;

                if (in_array($cursor->dayOfWeekIso, $weekdays, true)) {
                    $weeksDiff = (int) $startWeek->diffInWeeks($cursor->copy()->startOfWeek(Carbon::MONDAY));

                    if ($weeksDiff >= 0 && $weeksDiff % $interval === 0) {
                        $occurrenceStart = $cursor->copy()->setTimeFrom($start);

                        if ($occurrenceStart->gte($start) && ($endsOn === null || $occurrenceStart->lte($endsOn))) {
                            $occurrences[] = [
                                'start' => $occurrenceStart,
                                'deadline' => $occurrenceStart->copy()->addSeconds($durationSeconds),
                            ];
                        } elseif ($endsOn !== null && $occurrenceStart->gt($endsOn)) {
                            break;
                        }
                    }
                }

                $cursor->addDay();
            }

            return $occurrences;
        }

        for ($index = 0; $index < $maxCount; $index++) {
            $occurrenceStart = match ($unit) {
                'day' => $start->copy()->addDays($interval * $index),
                'month' => $start->copy()->addMonthsNoOverflow($interval * $index),
                'year' => $start->copy()->addYearsNoOverflow($interval * $index),
                default => $start->copy(),
            };

            if ($endsOn !== null && $occurrenceStart->gt($endsOn)) {
                break;
            }

            $occurrences[] = [
                'start' => $occurrenceStart,
                'deadline' => $occurrenceStart->copy()->addSeconds($durationSeconds),
            ];
        }

        return $occurrences;
    }
}
