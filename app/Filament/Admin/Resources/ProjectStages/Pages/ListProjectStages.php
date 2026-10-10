<?php

namespace App\Filament\Admin\Resources\ProjectStages\Pages;

use App\Filament\Admin\Resources\ProjectStages\ProjectStageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjectStages extends ListRecords
{
    protected static string $resource = ProjectStageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
