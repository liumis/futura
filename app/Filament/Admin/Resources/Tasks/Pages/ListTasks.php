<?php

namespace App\Filament\Admin\Resources\Tasks\Pages;

use App\Filament\Admin\Resources\Tasks\TaskResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    public function mount(): void
    {
        parent::mount();

        $archivedFilterValue = $this->tableFilters['archived']['value'] ?? null;

        if (! filled($archivedFilterValue)) {
            $this->tableFilters['archived'] = [
                'value' => 'active',
                'isActive' => true,
            ];
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
