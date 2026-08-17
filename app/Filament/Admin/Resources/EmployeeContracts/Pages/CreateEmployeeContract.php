<?php

namespace App\Filament\Admin\Resources\EmployeeContracts\Pages;

use App\Enums\EmployeeContractStatus;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractResource;
use App\Models\Employee;
use App\Models\EmployeeContract;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeContract extends CreateRecord
{
    protected static string $resource = EmployeeContractResource::class;

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $effectiveFrom = $data['effective_date_from'] ?? null;

        if ($employeeId > 0 && filled($effectiveFrom)) {
            $overlaps = EmployeeContract::query()
                ->overlappingPeriod(
                    employeeId: $employeeId,
                    effectiveFrom: (string) $effectiveFrom,
                    validTo: $data['valid_to'] ?? null,
                )
                ->exists();

            if ($overlaps) {
                $data['status'] = EmployeeContractStatus::Inactive->value;
            }
        }

        return $data;
    }
}
