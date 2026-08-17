<?php

namespace App\Filament\Admin\Resources\ExpenseTypes\Pages;

use App\Filament\Admin\Resources\ExpenseTypes\ExpenseTypeResource;
use App\Filament\Admin\Resources\Pages\EditRecord;

class EditExpenseType extends EditRecord
{
    protected static string $resource = ExpenseTypeResource::class;
}
