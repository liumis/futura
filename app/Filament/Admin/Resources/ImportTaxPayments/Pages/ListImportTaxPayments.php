<?php

namespace App\Filament\Admin\Resources\ImportTaxPayments\Pages;

use App\Filament\Admin\Resources\ImportTaxPayments\ImportTaxPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListImportTaxPayments extends ListRecords
{
    protected static string $resource = ImportTaxPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
