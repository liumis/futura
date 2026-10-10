<?php

namespace App\Filament\Admin\Resources\ProjectExpenses\Pages;

use App\Filament\Admin\Resources\ProjectExpenses\ProjectExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjectExpenses extends ListRecords
{
    protected static string $resource = ProjectExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
