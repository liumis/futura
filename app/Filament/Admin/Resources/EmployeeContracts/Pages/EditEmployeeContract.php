<?php

namespace App\Filament\Admin\Resources\EmployeeContracts\Pages;

use App\Filament\Admin\Concerns\HasDokobitSigningModal;
use App\Filament\Admin\Resources\EmployeeContracts\Concerns\AttachesSignedContractDocument;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractEndAction;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractResource;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractSignAction;
use App\Filament\Admin\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;

class EditEmployeeContract extends EditRecord
{
    use AttachesSignedContractDocument;
    use HasDokobitSigningModal;

    protected static string $resource = EmployeeContractResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractSignedDocumentUpload($data);
    }

    protected function afterSave(): void
    {
        $this->storeCapturedSignedDocument($this->record);

        $closed = EmployeeContractResource::closeOverlapsIfSigned($this->record);

        if ($closed === 0) {
            EmployeeContractResource::notifyOverlapOnSave($this->record);
        }
    }

    protected function buildHeaderActions(): array
    {
        return [
            EmployeeContractSignAction::make()
                ->record($this->getRecord()),
            EmployeeContractEndAction::make()
                ->record($this->getRecord()),
            DeleteAction::make(),
        ];
    }
}
