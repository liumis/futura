<?php

namespace App\Filament\Admin\Resources\DirectoryContactStages\Pages;

use App\Filament\Admin\Resources\DirectoryContactStages\DirectoryContactStageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDirectoryContactStages extends ListRecords
{
    protected static string $resource = DirectoryContactStageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
