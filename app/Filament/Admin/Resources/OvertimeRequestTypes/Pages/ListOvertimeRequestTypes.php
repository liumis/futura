<?php

namespace App\Filament\Admin\Resources\OvertimeRequestTypes\Pages;

use App\Filament\Admin\Resources\OvertimeRequestTypes\OvertimeRequestTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOvertimeRequestTypes extends ListRecords
{
    protected static string $resource = OvertimeRequestTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
