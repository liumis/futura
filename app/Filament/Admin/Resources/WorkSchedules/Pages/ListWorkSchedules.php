<?php

namespace App\Filament\Admin\Resources\WorkSchedules\Pages;

use App\Filament\Admin\Resources\WorkSchedules\WorkScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWorkSchedules extends ListRecords
{
    protected static string $resource = WorkScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
