<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LtHoliday extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'month',
        'day',
        'easter_offset',
        'recurrence_key',
    ];

    protected static function booted(): void
    {
        static::saving(function (LtHoliday $holiday): void {
            if ($holiday->easter_offset !== null) {
                $holiday->month = null;
                $holiday->day = null;
                $holiday->recurrence_key = 'easter:'.$holiday->easter_offset;

                return;
            }

            $holiday->easter_offset = null;
            $holiday->recurrence_key = 'fixed:'.$holiday->month.':'.$holiday->day;
        });
    }

    public function isEasterBased(): bool
    {
        return $this->easter_offset !== null;
    }

    public function recurrenceLabel(): string
    {
        if ($this->isEasterBased()) {
            return match ((int) $this->easter_offset) {
                0 => 'Easter Sunday',
                1 => 'Easter Monday',
                default => 'Easter + '.(int) $this->easter_offset.' days',
            };
        }

        return sprintf('%02d-%02d', (int) $this->month, (int) $this->day);
    }

    public function dateForYear(int $year): Carbon
    {
        if ($this->isEasterBased()) {
            return Carbon::createFromTimestamp(easter_date($year))
                ->startOfDay()
                ->addDays((int) $this->easter_offset);
        }

        return Carbon::create($year, (int) $this->month, (int) $this->day)->startOfDay();
    }

    /**
     * @return array<string, string> date (Y-m-d) => name
     */
    public static function datesBetween(Carbon|string $from, Carbon|string $to): array
    {
        $fromDate = ($from instanceof Carbon ? $from->copy() : Carbon::parse((string) $from))->startOfDay();
        $toDate = ($to instanceof Carbon ? $to->copy() : Carbon::parse((string) $to))->startOfDay();
        $dates = [];

        for ($year = $fromDate->year; $year <= $toDate->year; $year++) {
            foreach (static::query()->orderBy('month')->orderBy('day')->orderBy('easter_offset')->get() as $holiday) {
                $occurrence = $holiday->dateForYear($year);

                if ($occurrence->lt($fromDate) || $occurrence->gt($toDate)) {
                    continue;
                }

                $dates[$occurrence->toDateString()] = $holiday->name;
            }
        }

        ksort($dates);

        return $dates;
    }

    public static function isHoliday(Carbon|string $date): bool
    {
        return self::nameForDate($date) !== null;
    }

    public static function nameForDate(Carbon|string $date): ?string
    {
        $carbon = ($date instanceof Carbon ? $date->copy() : Carbon::parse((string) $date))->startOfDay();

        foreach (static::query()->get() as $holiday) {
            if ($holiday->dateForYear($carbon->year)->isSameDay($carbon)) {
                return $holiday->name;
            }
        }

        return null;
    }
}
