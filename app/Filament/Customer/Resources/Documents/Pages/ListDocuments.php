<?php

namespace App\Filament\Customer\Resources\Documents\Pages;

use App\Filament\Customer\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
