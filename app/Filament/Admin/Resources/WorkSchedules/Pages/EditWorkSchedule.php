<?php

namespace App\Filament\Admin\Resources\WorkSchedules\Pages;

use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Filament\Admin\Resources\WorkSchedules\Concerns\TogglesWorkScheduleWeekdays;
use App\Filament\Admin\Resources\WorkSchedules\WorkScheduleResource;
use App\Services\WorkScheduleGenerator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class EditWorkSchedule extends EditRecord
{
    use TogglesWorkScheduleWeekdays;

    protected static string $resource = WorkScheduleResource::class;

    /**
     * @var list<array{work_date: string, hours: mixed, is_not_working_day?: mixed}>|null
     */
    protected ?array $pendingScheduleEntries = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = parent::mutateFormDataBeforeFill($data);

        $entries = $this->record->entries()
            ->orderBy('work_date')
            ->get();

        if ($entries->isNotEmpty()) {
            $data['schedule_entries'] = $entries
                ->map(fn ($entry): array => [
                    'work_date' => $entry->work_date->toDateString(),
                    'hours' => (float) $entry->hours,
                    'actual_hours' => (float) ($entry->actual_hours ?? $entry->hours),
                    'is_not_working_day' => (bool) $entry->is_not_working_day,
                ])
                ->values()
                ->all();

            return $data;
        }

        $this->record->loadMissing('employee');

        if ($this->record->employee !== null) {
            $data['schedule_entries'] = WorkScheduleGenerator::forEmployeeMonth(
                $this->record->employee,
                $this->record->year,
                $this->record->month,
            );
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingScheduleEntries = is_array($data['schedule_entries'] ?? null)
            ? $data['schedule_entries']
            : [];

        unset($data['schedule_entries']);

        return parent::mutateFormDataBeforeSave($data);
    }

    protected function afterSave(): void
    {
        $this->record->syncEntriesFromForm($this->pendingScheduleEntries ?? []);
    }

    /**
     * @return array<\Filament\Actions\Action | \Filament\Actions\ActionGroup>
     */
    protected function buildHeaderActions(): array
    {
        return [
            Action::make('copyPlanToActual')
                ->label('Copy plan → actual')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Copy planned hours to actual')
                ->modalDescription('Overwrite every day’s actual hours with the planned hours for this month?')
                ->action(function (): void {
                    $state = method_exists($this->form, 'getRawState')
                        ? $this->form->getRawState()
                        : $this->form->getState();
                    $entries = $state['schedule_entries'] ?? [];

                    if (! is_array($entries) || $entries === []) {
                        Notification::make()
                            ->title('No timetable entries to update')
                            ->warning()
                            ->send();

                        return;
                    }

                    foreach ($entries as $index => $entry) {
                        $hours = (float) ($entry['hours'] ?? 0);
                        $entries[$index]['actual_hours'] = $hours;
                    }

                    $this->form->fill([
                        ...$state,
                        'schedule_entries' => array_values($entries),
                    ]);

                    Notification::make()
                        ->title('Actual hours set from plan')
                        ->success()
                        ->send();
                }),
            ...parent::buildHeaderActions(),
        ];
    }
}
