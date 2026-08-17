<?php

namespace App\Filament\Admin\Resources\Projects\Pages;

use App\Filament\Admin\Resources\Projects\ProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

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
