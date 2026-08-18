<?php

namespace App\Filament\Admin\Resources\Shareholders\Pages;

use App\Filament\Admin\Resources\Shareholders\ShareholderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShareholders extends ListRecords
{
    protected static string $resource = ShareholderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
