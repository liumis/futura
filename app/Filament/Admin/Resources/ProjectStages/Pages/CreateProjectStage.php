<?php

namespace App\Filament\Admin\Resources\ProjectStages\Pages;

use App\Filament\Admin\Resources\ProjectStages\ProjectStageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectStage extends CreateRecord
{
    protected static string $resource = ProjectStageResource::class;
}
