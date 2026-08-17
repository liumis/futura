<?php

namespace App\Filament\Admin\Resources\ImportTaxes\Pages;

use App\Filament\Admin\Resources\ImportTaxes\ImportTaxResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListImportTaxes extends ListRecords
{
    protected static string $resource = ImportTaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
