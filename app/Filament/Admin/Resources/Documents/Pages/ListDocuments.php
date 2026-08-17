<?php

namespace App\Filament\Admin\Resources\Documents\Pages;

use App\Filament\Admin\Concerns\HasDokobitSigningModal;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    use HasDokobitSigningModal;

    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
