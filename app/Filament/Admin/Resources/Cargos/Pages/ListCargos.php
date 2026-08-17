<?php

namespace App\Filament\Admin\Resources\Cargos\Pages;

use App\Filament\Admin\Resources\Cargos\CargoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCargos extends ListRecords
{
    protected static string $resource = CargoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
