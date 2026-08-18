<?php

namespace App\Filament\Admin\Resources\Shareholders\Pages;

use App\Filament\Admin\Resources\Shareholders\ShareholderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShareholder extends CreateRecord
{
    protected static string $resource = ShareholderResource::class;
}
