<?php

namespace App\Filament\Admin\Resources\CustomsContacts\Pages;

use App\Filament\Admin\Resources\CustomsContacts\CustomsContactResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomsContacts extends ListRecords
{
    protected static string $resource = CustomsContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
