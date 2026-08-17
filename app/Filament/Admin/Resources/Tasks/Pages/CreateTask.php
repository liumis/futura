<?php

namespace App\Filament\Admin\Resources\Tasks\Pages;

use App\Enums\TodoStatus;
use App\Filament\Admin\Concerns\UploadsTaskToSharepoint;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Models\Todo;
use App\Support\TodoRecurrence;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CreateTask extends CreateRecord
{
    use UploadsTaskToSharepoint;

    protected static string $resource = TaskResource::class;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $recurrenceConfig = null;

    /**
     * @var list<string>
     */
    protected array $pendingLocalUploads = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = TodoStatus::New->value;

        $this->pendingLocalUploads = $this->normalizeUploadedFilePaths($data['attachments'] ?? null);
        unset($data['attachments']);

        $raw = array_merge(
            $this->form->getRawState(),
            is_array($this->data) ? $this->data : [],
        );

        if (! (bool) ($raw['is_recurring'] ?? false)) {
            $this->recurrenceConfig = null;

            return $data;
        }

        $this->recurrenceConfig = [
            'interval' => max(1, (int) ($raw['recurrence_interval'] ?? 1)),
            'unit' => (string) ($raw['recurrence_unit'] ?? 'week'),
            'weekdays' => array_values(array_filter(
                Arr::wrap($raw['recurrence_weekdays'] ?? []),
                fn ($day): bool => filled($day),
            )),
            'ends' => (string) ($raw['recurrence_ends'] ?? 'after'),
            'ends_on' => $raw['recurrence_ends_on'] ?? null,
            'occurrences' => max(1, (int) ($raw['recurrence_occurrences'] ?? 13)),
        ];

        if (($this->recurrenceConfig['ends'] ?? '') === 'on' && blank($this->recurrenceConfig['ends_on'])) {
            throw ValidationException::withMessages([
                'data.recurrence_ends_on' => 'Please choose an end date for the recurrence.',
            ]);
        }

        if (
            ($this->recurrenceConfig['unit'] ?? '') === 'week'
            && ($this->recurrenceConfig['weekdays'] ?? []) === []
        ) {
            throw ValidationException::withMessages([
                'data.recurrence_weekdays' => 'Please choose at least one weekday.',
            ]);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->uploadTaskFilesToSharepoint($this->record, $this->pendingLocalUploads);
        $this->pendingLocalUploads = [];

        if ($this->recurrenceConfig === null) {
            return;
        }

        /** @var Todo $record */
        $record = $this->record;

        $start = $record->start_date instanceof Carbon
            ? $record->start_date->copy()
            : Carbon::parse($record->deadline ?? now())->subHour();

        $deadline = $record->deadline instanceof Carbon
            ? $record->deadline->copy()
            : $start->copy()->addHour();

        try {
            $occurrences = TodoRecurrence::expand($start, $deadline, $this->recurrenceConfig);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not create recurring tasks')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($occurrences === []) {
            return;
        }

        // Align the first saved todo to the first calculated occurrence
        // (important when weekly days differ from the original start date).
        $first = $occurrences[0];
        $record->update([
            'start_date' => $first['start'],
            'deadline' => $first['deadline'],
        ]);

        $watcherIds = $record->watchers()->pluck('users.id')->all();
        $created = 1;

        foreach (array_slice($occurrences, 1) as $occurrence) {
            $clone = $record->replicate([
                'created_at',
                'updated_at',
                'attachments',
                'sharepoint_files',
                'sharepoint_web_url',
                'sharepoint_item_id',
                'sharepoint_path',
            ]);
            $clone->start_date = $occurrence['start'];
            $clone->deadline = $occurrence['deadline'];
            $clone->attachments = null;
            $clone->sharepoint_files = null;
            $clone->sharepoint_web_url = null;
            $clone->sharepoint_item_id = null;
            $clone->sharepoint_path = null;
            $clone->save();

            if ($watcherIds !== []) {
                $clone->watchers()->sync($watcherIds);
            }

            $created++;
        }

        if ($created > 1) {
            Notification::make()
                ->title("Created {$created} recurring tasks")
                ->success()
                ->send();
        }
    }
}
