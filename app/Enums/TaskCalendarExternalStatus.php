<?php

namespace App\Enums;

enum TaskCalendarExternalStatus: string
{
    case Synced = 'synced';
    case Pending = 'pending';
    case DeletedExternally = 'deleted_externally';
    case Error = 'error';
}
