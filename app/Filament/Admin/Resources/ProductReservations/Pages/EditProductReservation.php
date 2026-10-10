<?php

namespace App\Filament\Admin\Resources\ProductReservations\Pages;

use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Filament\Admin\Resources\ProductReservations\ProductReservationResource;
use Filament\Actions;

class EditProductReservation extends EditRecord
{
    protected static string $resource = ProductReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
