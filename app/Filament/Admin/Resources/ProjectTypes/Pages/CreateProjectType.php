<?php

namespace App\Filament\Admin\Resources\ProjectTypes\Pages;

use App\Filament\Admin\Resources\ProjectTypes\ProjectTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProjectType extends CreateRecord
{
    protected static string $resource = ProjectTypeResource::class;
}
