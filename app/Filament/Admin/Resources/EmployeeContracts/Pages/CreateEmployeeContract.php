<?php

namespace App\Filament\Admin\Resources\EmployeeContracts\Pages;

use App\Filament\Admin\Resources\EmployeeContracts\Concerns\AttachesSignedContractDocument;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractResource;
use App\Models\Employee;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeContract extends CreateRecord
{
    use AttachesSignedContractDocument;

    protected static string $resource = EmployeeContractResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->extractSignedDocumentUpload($data);
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $data = [];
        $employeeId = (int) request()->query('employee_id');

        if ($employeeId > 0 && Employee::query()->whereKey($employeeId)->exists()) {
            $data['employee_id'] = $employeeId;
        }

        $this->form->fill($data);

        $this->callHook('afterFill');
    }

    protected function afterCreate(): void
    {
        $this->storeCapturedSignedDocument($this->record);

        $closed = EmployeeContractResource::closeOverlapsIfSigned($this->record);

        if ($closed === 0) {
            EmployeeContractResource::notifyOverlapOnSave($this->record);
        }
    }
}
