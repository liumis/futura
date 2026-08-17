<?php

namespace App\Filament\Admin\Resources\InvoiceSeries\Pages;

use App\Filament\Admin\Resources\InvoiceSeries\InvoiceSeriesResource;
use App\Models\InvoiceSeries;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoiceSeries extends CreateRecord
{
    protected static string $resource = InvoiceSeriesResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! ($data['is_default'] ?? false) && ! InvoiceSeries::query()->where('is_default', true)->exists()) {
            $data['is_default'] = true;
        }

        return $data;
    }
}
