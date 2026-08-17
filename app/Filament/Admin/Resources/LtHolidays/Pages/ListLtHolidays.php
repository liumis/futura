<?php

namespace App\Filament\Admin\Resources\LtHolidays\Pages;

use App\Filament\Admin\Resources\LtHolidays\LtHolidayResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLtHolidays extends ListRecords
{
    protected static string $resource = LtHolidayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
