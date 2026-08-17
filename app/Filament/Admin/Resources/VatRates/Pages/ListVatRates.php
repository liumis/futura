<?php

namespace App\Filament\Admin\Resources\VatRates\Pages;

use App\Filament\Admin\Resources\VatRates\VatRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVatRates extends ListRecords
{
    protected static string $resource = VatRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
