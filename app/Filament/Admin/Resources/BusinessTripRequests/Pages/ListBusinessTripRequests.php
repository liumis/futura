<?php

namespace App\Filament\Admin\Resources\BusinessTripRequests\Pages;

use App\Filament\Admin\Resources\BusinessTripRequests\BusinessTripRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListBusinessTripRequests extends ListRecords
{
    protected static string $resource = BusinessTripRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableQuery(): ?Builder
    {
        return parent::getTableQuery()?->with(['employee', 'documents']);
    }
}
