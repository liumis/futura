<?php

namespace App\Filament\Admin\Resources\ProductReservations\Pages;

use App\Filament\Admin\Resources\ProductReservations\ProductReservationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductReservations extends ListRecords
{
    protected static string $resource = ProductReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Add reservation'),
        ];
    }
}
