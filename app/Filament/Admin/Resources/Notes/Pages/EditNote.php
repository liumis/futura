<?php

namespace App\Filament\Admin\Resources\Notes\Pages;

use App\Filament\Admin\Concerns\UploadsNoteToSharepoint;
use App\Filament\Admin\Resources\Notes\NoteResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use Filament\Actions;

class EditNote extends EditRecord
{
    use UploadsNoteToSharepoint;

    protected static string $resource = NoteResource::class;

    /**
     * @var list<string>
     */
    protected array $pendingLocalUploads = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['attachments'] = [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['user_id']);

        $this->pendingLocalUploads = $this->normalizeUploadedFilePaths($data['attachments'] ?? null);
        unset($data['attachments']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->uploadNoteFilesToSharepoint($this->record, $this->pendingLocalUploads);
        $this->pendingLocalUploads = [];

        $this->record->refresh();
        $this->fillForm();
    }
}
