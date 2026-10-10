<?php

namespace App\Filament\Admin\Resources\Notes\Pages;

use App\Enums\NoteStatus;
use App\Filament\Admin\Concerns\UploadsNoteToSharepoint;
use App\Filament\Admin\Resources\Notes\NoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNote extends CreateRecord
{
    use UploadsNoteToSharepoint;

    protected static string $resource = NoteResource::class;

    /**
     * @var list<string>
     */
    protected array $pendingLocalUploads = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['status'] = $data['status'] ?? NoteStatus::Active->value;

        $this->pendingLocalUploads = $this->normalizeUploadedFilePaths($data['attachments'] ?? null);
        unset($data['attachments']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->uploadNoteFilesToSharepoint($this->record, $this->pendingLocalUploads);
        $this->pendingLocalUploads = [];
    }
}
