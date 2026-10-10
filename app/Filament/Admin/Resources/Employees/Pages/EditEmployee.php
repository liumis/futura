<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Filament\Admin\Concerns\HasDokobitSigningModal;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractEndAction;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;

class EditEmployee extends EditRecord
{
    use HasDokobitSigningModal;

    protected static string $resource = EmployeeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($data['second_pillar_enrolled'])) {
            $data['second_pillar_enrolled'] = false;
            $data['second_pillar_rate'] = null;
        }

        return $data;
    }

    /**
     * @return array<int, Action>
     */
    protected function buildHeaderActions(): array
    {
        return [
            EmployeeContractEndAction::make()
                ->record($this->getRecord()),
            DeleteAction::make(),
        ];
    }
}
