<?php

namespace App\Filament\Admin\Resources\BusinessTripDailyAllowances\Pages;

use App\Filament\Admin\Resources\BusinessTripDailyAllowances\BusinessTripDailyAllowanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBusinessTripDailyAllowances extends ListRecords
{
    protected static string $resource = BusinessTripDailyAllowanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
