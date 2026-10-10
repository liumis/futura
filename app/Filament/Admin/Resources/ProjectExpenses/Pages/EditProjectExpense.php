<?php

namespace App\Filament\Admin\Resources\ProjectExpenses\Pages;

use App\Filament\Admin\Concerns\UploadsProjectExpenseToSharepoint;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Filament\Admin\Resources\ProjectExpenses\ProjectExpenseResource;
use Filament\Actions;

class EditProjectExpense extends EditRecord
{
    use UploadsProjectExpenseToSharepoint;

    protected static string $resource = ProjectExpenseResource::class;

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
        $this->uploadProjectExpenseFilesToSharepoint($this->record, $this->pendingLocalUploads);
        $this->pendingLocalUploads = [];

        $this->record->refresh();
        $this->fillForm();
    }
}
