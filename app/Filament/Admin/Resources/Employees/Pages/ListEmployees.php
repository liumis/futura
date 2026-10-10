<?php

namespace App\Filament\Admin\Resources\Employees\Pages;

use App\Enums\EmployeeStatus;
use App\Filament\Admin\Concerns\HasDokobitSigningModal;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListEmployees extends ListRecords
{
    use HasDokobitSigningModal;

    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableQuery(): ?Builder
    {
        return parent::getTableQuery()
            ?->with(['user', 'employeeContracts.terminationDocument'])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [EmployeeStatus::Active->value])
            ->orderBy('surname')
            ->orderBy('name');
    }
}
