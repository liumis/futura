<?php

namespace App\Filament\Admin\Resources\EmailLogs\Pages;

use App\Filament\Admin\Resources\EmailLogs\EmailLogResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailLogs extends ListRecords
{
    protected static string $resource = EmailLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
