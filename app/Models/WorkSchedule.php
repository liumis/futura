<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class WorkSchedule extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'year',
        'month',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WorkScheduleEntry::class)->orderBy('work_date');
    }

    public function monthLabel(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('F Y');
    }

    public function totalHours(): float
    {
        return (float) $this->entries()->sum('hours');
    }

    public function totalDaysCount(): int
    {
        if (array_key_exists('entries_count', $this->getAttributes())) {
            return (int) $this->getAttribute('entries_count');
        }

        return (int) $this->entries()->count();
    }

    public function workingDaysCount(): int
    {
        if (array_key_exists('working_entries_count', $this->getAttributes())) {
            return (int) $this->getAttribute('working_entries_count');
        }

        return (int) $this->entries()
            ->where('hours', '>', 0)
            ->count();
    }

    public function actualWorkingDaysCount(): int
    {
        if (array_key_exists('actual_working_entries_count', $this->getAttributes())) {
            return (int) $this->getAttribute('actual_working_entries_count');
        }

        return (int) $this->entries()
            ->where('actual_hours', '>', 0)
            ->count();
    }

    public function monthlyHours(): float
    {
        if (array_key_exists('entries_sum_hours', $this->getAttributes())) {
            return (float) $this->getAttribute('entries_sum_hours');
        }

        return $this->totalHours();
    }

    public function actualMonthlyHours(): float
    {
        if (array_key_exists('entries_sum_actual_hours', $this->getAttributes())) {
            return (float) $this->getAttribute('entries_sum_actual_hours');
        }

        return (float) $this->entries()->sum('actual_hours');
    }

    /**
     * @return array{
     *     has_schedule: bool,
     *     schedule_id: int|null,
     *     planned_hours: float,
     *     actual_hours: float,
     *     planned_working_days: int,
     *     actual_working_days: int,
     * }
     */
    public static function monthSummaryForEmployee(int $employeeId, int $year, int $month): array
    {
        $schedule = static::query()
            ->where('employee_id', $employeeId)
            ->where('year', $year)
            ->where('month', $month)
            ->withSum('entries', 'hours')
            ->withSum('entries as entries_sum_actual_hours', 'actual_hours')
            ->withCount([
                'entries as working_entries_count' => fn ($query) => $query->where('hours', '>', 0),
                'entries as actual_working_entries_count' => fn ($query) => $query->where('actual_hours', '>', 0),
            ])
            ->first();

        if ($schedule === null) {
            return [
                'has_schedule' => false,
                'schedule_id' => null,
                'planned_hours' => 0.0,
                'actual_hours' => 0.0,
                'planned_working_days' => 0,
                'actual_working_days' => 0,
            ];
        }

        return [
            'has_schedule' => true,
            'schedule_id' => (int) $schedule->getKey(),
            'planned_hours' => $schedule->monthlyHours(),
            'actual_hours' => $schedule->actualMonthlyHours(),
            'planned_working_days' => $schedule->workingDaysCount(),
            'actual_working_days' => $schedule->actualWorkingDaysCount(),
        ];
    }

    /**
     * Pro-rate a monthly base salary by actual vs planned hours.
     */
    public static function prorateSalary(float $baseSalary, float $plannedHours, float $actualHours): float
    {
        if ($plannedHours <= 0) {
            return round(max(0, $baseSalary), 2);
        }

        return round(max(0, $baseSalary) * (max(0, $actualHours) / $plannedHours), 2);
    }

    /**
     * @param  list<array{work_date?: string, hours?: mixed, actual_hours?: mixed, is_not_working_day?: mixed}>  $entries
     */
    public function syncEntriesFromForm(array $entries): void
    {
        foreach ($entries as $entry) {
            if (blank($entry['work_date'] ?? null)) {
                continue;
            }

            $isNotWorkingDay = (bool) ($entry['is_not_working_day'] ?? false);
            $hours = $isNotWorkingDay ? 0 : (float) ($entry['hours'] ?? 0);
            $actualHours = $isNotWorkingDay ? 0 : (float) ($entry['actual_hours'] ?? $hours);

            $this->entries()->updateOrCreate(
                ['work_date' => $entry['work_date']],
                [
                    'hours' => $hours,
                    'actual_hours' => $actualHours,
                    'is_not_working_day' => $isNotWorkingDay,
                ],
            );
        }
    }
}
