<?php

namespace App\Filament\Admin\Resources\CustomsContacts\Pages;

use App\Filament\Admin\Resources\CustomsContacts\CustomsContactResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomsContact extends CreateRecord
{
    protected static string $resource = CustomsContactResource::class;
}
