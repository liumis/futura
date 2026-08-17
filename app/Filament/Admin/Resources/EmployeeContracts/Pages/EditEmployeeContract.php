<?php

namespace App\Filament\Admin\Resources\EmployeeContracts\Pages;

use App\Enums\EmployeeContractStatus;
use App\Filament\Admin\Concerns\HasDokobitSigningModal;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractResource;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractSignAction;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Models\EmployeeContract;
use Filament\Actions\DeleteAction;

class EditEmployeeContract extends EditRecord
{
    use HasDokobitSigningModal;

    protected static string $resource = EmployeeContractResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $effectiveFrom = $data['effective_date_from'] ?? null;
        $recordId = (int) ($this->record?->getKey() ?? 0);

        if ($employeeId > 0 && filled($effectiveFrom)) {
            $overlaps = EmployeeContract::query()
                ->overlappingPeriod(
                    employeeId: $employeeId,
                    effectiveFrom: (string) $effectiveFrom,
                    validTo: $data['valid_to'] ?? null,
                    ignoreId: $recordId > 0 ? $recordId : null,
                )
                ->exists();

            if ($overlaps) {
                $data['status'] = EmployeeContractStatus::Inactive->value;
            }
        }

        return $data;
    }

    protected function buildHeaderActions(): array
    {
        return [
            EmployeeContractSignAction::make()
                ->record($this->getRecord()),
            DeleteAction::make(),
        ];
    }
}
