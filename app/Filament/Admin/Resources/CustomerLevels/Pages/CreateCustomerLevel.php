<?php

namespace App\Filament\Admin\Resources\CustomerLevels\Pages;

use App\Filament\Admin\Resources\CustomerLevels\CustomerLevelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerLevel extends CreateRecord
{
    protected static string $resource = CustomerLevelResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
