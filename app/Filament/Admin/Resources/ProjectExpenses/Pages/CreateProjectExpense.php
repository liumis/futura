<?php

namespace App\Filament\Admin\Resources\ProjectExpenses\Pages;

use App\Filament\Admin\Concerns\UploadsProjectExpenseToSharepoint;
use App\Filament\Admin\Resources\ProjectExpenses\ProjectExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectExpense extends CreateRecord
{
    use UploadsProjectExpenseToSharepoint;

    protected static string $resource = ProjectExpenseResource::class;

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

        $this->pendingLocalUploads = $this->normalizeUploadedFilePaths($data['attachments'] ?? null);
        unset($data['attachments']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->uploadProjectExpenseFilesToSharepoint($this->record, $this->pendingLocalUploads);
        $this->pendingLocalUploads = [];
    }
}
