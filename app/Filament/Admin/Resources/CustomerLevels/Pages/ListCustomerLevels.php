<?php

namespace App\Filament\Admin\Resources\CustomerLevels\Pages;

use App\Filament\Admin\Resources\CustomerLevels\CustomerLevelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomerLevels extends ListRecords
{
    protected static string $resource = CustomerLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
