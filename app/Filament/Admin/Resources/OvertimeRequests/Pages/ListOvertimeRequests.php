<?php

namespace App\Filament\Admin\Resources\OvertimeRequests\Pages;

use App\Filament\Admin\Resources\OvertimeRequests\OvertimeRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOvertimeRequests extends ListRecords
{
    protected static string $resource = OvertimeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
