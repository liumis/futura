<?php

namespace App\Filament\Admin\Resources\StockManualUpdates\Pages;

use App\Filament\Admin\Resources\StockManualUpdates\StockManualUpdateResource;
use Filament\Resources\Pages\ListRecords;

class ListStockManualUpdates extends ListRecords
{
    protected static string $resource = StockManualUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
