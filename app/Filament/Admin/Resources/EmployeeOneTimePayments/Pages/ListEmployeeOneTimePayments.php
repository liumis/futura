<?php

namespace App\Filament\Admin\Resources\EmployeeOneTimePayments\Pages;

use App\Filament\Admin\Resources\EmployeeOneTimePayments\EmployeeOneTimePaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeOneTimePayments extends ListRecords
{
    protected static string $resource = EmployeeOneTimePaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
