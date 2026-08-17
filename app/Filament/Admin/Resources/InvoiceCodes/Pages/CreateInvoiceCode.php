<?php

namespace App\Filament\Admin\Resources\InvoiceCodes\Pages;

use App\Filament\Admin\Resources\InvoiceCodes\InvoiceCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoiceCode extends CreateRecord
{
    protected static string $resource = InvoiceCodeResource::class;
}
