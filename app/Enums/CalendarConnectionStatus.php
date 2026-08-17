<?php

namespace App\Enums;

enum CalendarConnectionStatus: string
{
    case Active = 'active';
    case Disconnected = 'disconnected';
    case Error = 'error';
}
