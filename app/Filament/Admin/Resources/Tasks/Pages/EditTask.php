<?php

namespace App\Filament\Admin\Resources\Tasks\Pages;

use App\Filament\Admin\Concerns\UploadsTaskToSharepoint;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Models\TaskCalendarEvent;
use App\Services\Calendar\CalendarSyncService;
use App\Services\SharepointTaskUploader;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class EditTask extends EditRecord
{
    use UploadsTaskToSharepoint;

    protected static string $resource = TaskResource::class;

    /**
     * @var array<string, mixed>
     */
    protected array $pendingCommentsFormData = [];

    /**
     * @var list<string>
     */
    protected array $pendingLocalUploads = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        SharepointTaskUploader::backfillUploadedAt($this->record);
        $this->record->refresh();

        $data['comments_history'] = TaskResource::commentHistoryRows($this->record);
        $data['new_comment_content'] = '';
        $data['new_comment_attachments'] = [];
        // SharePoint files are shown separately; FileUpload is for new files only.
        $data['attachments'] = [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingCommentsFormData = [
            'comments_history' => $data['comments_history'] ?? [],
            'new_comment_content' => $data['new_comment_content'] ?? '',
            'new_comment_attachments' => $data['new_comment_attachments'] ?? [],
        ];

        $this->pendingLocalUploads = $this->normalizeUploadedFilePaths($data['attachments'] ?? null);
        unset($data['attachments'], $data['comments_history'], $data['new_comment_content'], $data['new_comment_attachments']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->uploadTaskFilesToSharepoint($this->record, $this->pendingLocalUploads);
        $this->pendingLocalUploads = [];

        TaskResource::syncTaskCommentsFromFormData($this->record, $this->pendingCommentsFormData);
        $this->pendingCommentsFormData = [];

        $this->record->refresh();
        $this->fillForm();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('restoreToOutlook')
                ->label('Restore to Outlook Calendar')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Restore Outlook event')
                ->modalDescription('Recreate the Outlook calendar event for this task. The task itself was never deleted.')
                ->visible(function (): bool {
                    $mapping = TaskCalendarEvent::query()
                        ->where('todo_id', $this->record->getKey())
                        ->first();

                    return $mapping?->canRestoreToOutlook() ?? false;
                })
                ->action(function (CalendarSyncService $sync): void {
                    try {
                        $sync->restoreTodoToOutlook($this->record->fresh());
                        Notification::make()
                            ->title('Outlook event restore queued/completed')
                            ->success()
                            ->send();
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Could not restore Outlook event')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }

                    $this->record->refresh();
                }),
        ];
    }
}
