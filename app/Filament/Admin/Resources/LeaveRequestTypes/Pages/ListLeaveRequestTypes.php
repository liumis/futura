<?php

namespace App\Filament\Admin\Resources\LeaveRequestTypes\Pages;

use App\Filament\Admin\Resources\LeaveRequestTypes\LeaveRequestTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLeaveRequestTypes extends ListRecords
{
    protected static string $resource = LeaveRequestTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
