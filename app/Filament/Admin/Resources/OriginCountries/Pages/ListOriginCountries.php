<?php

namespace App\Filament\Admin\Resources\OriginCountries\Pages;

use App\Filament\Admin\Resources\OriginCountries\OriginCountryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOriginCountries extends ListRecords
{
    protected static string $resource = OriginCountryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
