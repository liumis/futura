<?php

namespace App\Filament\Admin\Resources\WorkSchedules\Concerns;

use App\Models\Employee;
use App\Services\WorkScheduleGenerator;
use Illuminate\Support\Carbon;

trait TogglesWorkScheduleWeekdays
{
    public function toggleScheduleWeekday(int $isoWeekday): void
    {
        if ($isoWeekday < 1 || $isoWeekday > 7) {
            return;
        }

        $state = method_exists($this->form, 'getRawState')
            ? $this->form->getRawState()
            : $this->form->getState();
        $entries = $state['schedule_entries'] ?? [];

        if (! is_array($entries) || $entries === []) {
            return;
        }

        $employee = Employee::query()->find($state['employee_id'] ?? null);
        $defaultHours = $employee !== null
            ? WorkScheduleGenerator::defaultHoursFor($employee)
            : WorkScheduleGenerator::BASE_HOURS_PER_DAY;

        $anyOn = false;

        foreach ($entries as $entry) {
            if (blank($entry['work_date'] ?? null)) {
                continue;
            }

            if (Carbon::parse((string) $entry['work_date'])->dayOfWeekIso !== $isoWeekday) {
                continue;
            }

            if (! (bool) ($entry['is_not_working_day'] ?? false) && (float) ($entry['hours'] ?? 0) > 0) {
                $anyOn = true;

                break;
            }
        }

        $turnOn = ! $anyOn;

        foreach ($entries as $index => $entry) {
            if (blank($entry['work_date'] ?? null)) {
                continue;
            }

            if (Carbon::parse((string) $entry['work_date'])->dayOfWeekIso !== $isoWeekday) {
                continue;
            }

            if ($turnOn) {
                $entries[$index]['is_not_working_day'] = false;
                $entries[$index]['hours'] = $defaultHours;
                $entries[$index]['actual_hours'] = $defaultHours;

                continue;
            }

            $entries[$index]['is_not_working_day'] = true;
            $entries[$index]['hours'] = 0;
            $entries[$index]['actual_hours'] = 0;
        }

        foreach ($entries as $index => $entry) {
            $isOff = (bool) ($entry['is_not_working_day'] ?? false);
            $hours = $isOff ? 0 : (float) ($entry['hours'] ?? 0);

            $entries[$index]['hours'] = $hours;
            $entries[$index]['actual_hours'] = $isOff
                ? 0
                : (float) ($entry['actual_hours'] ?? $hours);
        }

        $this->form->fill([
            ...$state,
            'schedule_entries' => array_values($entries),
        ]);
    }
}
