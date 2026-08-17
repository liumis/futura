<?php

namespace App\Filament\Admin\Resources\WriteOffDocuments\Pages;

use App\Filament\Admin\Resources\WriteOffDocuments\WriteOffDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListWriteOffDocuments extends ListRecords
{
    protected static string $resource = WriteOffDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
