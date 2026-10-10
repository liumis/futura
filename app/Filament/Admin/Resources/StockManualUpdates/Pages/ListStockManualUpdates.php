<?php

namespace App\Filament\Admin\Resources\StockManualUpdates\Pages;

use App\Filament\Admin\Resources\StockManualUpdates\StockManualUpdateResource;
use Filament\Resources\Pages\ListRecords;

class ListStockManualUpdates extends ListRecords
{
    protected static string $resource = StockManualUpdateResource::class;

    public function getTitle(): string
    {
        return 'Write-off history';
    }

    public function getSubheading(): ?string
    {
        return 'History of stock decreases from Stock. Select undocumented rows to create a write-off document — quantities are not edited here.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
