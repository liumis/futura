<?php

namespace App\Filament\Admin\Resources\ProductReservations\Pages;

use App\Filament\Admin\Resources\ProductReservations\ProductReservationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductReservation extends CreateRecord
{
    protected static string $resource = ProductReservationResource::class;
}
