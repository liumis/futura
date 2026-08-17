<?php

namespace App\Filament\Admin\Resources\EmployeeContracts\Pages;

use App\Filament\Admin\Concerns\HasDokobitSigningModal;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeContracts extends ListRecords
{
    use HasDokobitSigningModal;

    protected static string $resource = EmployeeContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
