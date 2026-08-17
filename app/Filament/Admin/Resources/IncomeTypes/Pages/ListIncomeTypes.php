<?php

namespace App\Filament\Admin\Resources\IncomeTypes\Pages;

use App\Filament\Admin\Resources\IncomeTypes\IncomeTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIncomeTypes extends ListRecords
{
    protected static string $resource = IncomeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
