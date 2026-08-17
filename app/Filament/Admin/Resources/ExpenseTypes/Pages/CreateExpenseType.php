<?php

namespace App\Filament\Admin\Resources\ExpenseTypes\Pages;

use App\Filament\Admin\Resources\ExpenseTypes\ExpenseTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpenseType extends CreateRecord
{
    protected static string $resource = ExpenseTypeResource::class;
}
