<?php

namespace App\Filament\Admin\Resources\Dividends\Pages;

use App\Filament\Admin\Resources\Dividends\DividendResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDividends extends ListRecords
{
    protected static string $resource = DividendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
