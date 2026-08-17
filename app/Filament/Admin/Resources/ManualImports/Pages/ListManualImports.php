<?php

namespace App\Filament\Admin\Resources\ManualImports\Pages;

use App\Filament\Admin\Resources\ManualImports\ManualImportResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListManualImports extends ListRecords
{
    protected static string $resource = ManualImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
