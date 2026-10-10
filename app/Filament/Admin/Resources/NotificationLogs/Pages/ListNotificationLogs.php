<?php

namespace App\Filament\Admin\Resources\NotificationLogs\Pages;

use App\Filament\Admin\Resources\NotificationLogs\NotificationLogResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationLogs extends ListRecords
{
    protected static string $resource = NotificationLogResource::class;
}
