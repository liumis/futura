<?php

namespace App\Filament\Admin\Resources\IncomeTypes\Pages;

use App\Filament\Admin\Resources\IncomeTypes\IncomeTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIncomeType extends CreateRecord
{
    protected static string $resource = IncomeTypeResource::class;
}
