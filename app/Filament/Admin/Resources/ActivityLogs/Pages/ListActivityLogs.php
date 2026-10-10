<?php

namespace App\Filament\Admin\Resources\ActivityLogs\Pages;

use App\Filament\Admin\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    public function getSubheading(): ?string
    {
        return 'Model create/update/delete, file uploads and opens, stock Excel imports, and report generation/downloads. Entries older than 3 years are removed daily.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
