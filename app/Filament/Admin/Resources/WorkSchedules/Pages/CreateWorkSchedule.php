<?php

namespace App\Filament\Admin\Resources\WorkSchedules\Pages;

use App\Filament\Admin\Resources\WorkSchedules\Concerns\TogglesWorkScheduleWeekdays;
use App\Filament\Admin\Resources\WorkSchedules\WorkScheduleResource;
use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\WorkScheduleGenerator;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkSchedule extends CreateRecord
{
    use TogglesWorkScheduleWeekdays;
    protected static string $resource = WorkScheduleResource::class;

    /**
     * @var list<array{work_date: string, hours: mixed}>|null
     */
    protected ?array $pendingScheduleEntries = null;

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $data = [
            'year' => (int) now()->year,
            'month' => (int) now()->month,
        ];

        $employeeId = (int) request()->query('employee_id');

        if ($employeeId > 0 && Employee::query()->whereKey($employeeId)->exists()) {
            $data['employee_id'] = $employeeId;
        }

        if (filled($data['employee_id'] ?? null)) {
            $employee = Employee::query()->find($data['employee_id']);

            if ($employee !== null) {
                $data['schedule_entries'] = WorkScheduleGenerator::forEmployeeMonth(
                    $employee,
                    (int) $data['year'],
                    (int) $data['month'],
                );
            }
        }

        $this->form->fill($data);

        $this->callHook('afterFill');
    }

    protected function beforeCreate(): void
    {
        $data = $this->form->getState();

        $exists = WorkSchedule::query()
            ->where('employee_id', $data['employee_id'])
            ->where('year', $data['year'])
            ->where('month', $data['month'])
            ->exists();

        if ($exists) {
            Notification::make()
                ->title('Schedule already exists')
                ->body('This employee already has a work schedule for the selected month.')
                ->warning()
                ->send();

            $this->halt();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingScheduleEntries = is_array($data['schedule_entries'] ?? null)
            ? $data['schedule_entries']
            : [];

        unset($data['schedule_entries']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncScheduleEntries($this->pendingScheduleEntries ?? []);
    }

    /**
     * @param  list<array{work_date?: string, hours?: mixed, is_not_working_day?: mixed}>  $entries
     */
    protected function syncScheduleEntries(array $entries): void
    {
        $this->record->syncEntriesFromForm($entries);
    }
}
