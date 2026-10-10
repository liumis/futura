<?php

namespace App\Filament\Admin\Resources\ProjectTypes\Pages;

use App\Filament\Admin\Resources\ProjectTypes\ProjectTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjectTypes extends ListRecords
{
    protected static string $resource = ProjectTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
