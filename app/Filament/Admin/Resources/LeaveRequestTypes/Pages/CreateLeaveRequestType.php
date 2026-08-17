<?php

namespace App\Filament\Admin\Resources\LeaveRequestTypes\Pages;

use App\Filament\Admin\Resources\LeaveRequestTypes\LeaveRequestTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLeaveRequestType extends CreateRecord
{
    protected static string $resource = LeaveRequestTypeResource::class;
}
