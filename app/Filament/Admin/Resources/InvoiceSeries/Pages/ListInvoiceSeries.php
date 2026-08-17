<?php

namespace App\Filament\Admin\Resources\InvoiceSeries\Pages;

use App\Filament\Admin\Resources\InvoiceSeries\InvoiceSeriesResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInvoiceSeries extends ListRecords
{
    protected static string $resource = InvoiceSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
