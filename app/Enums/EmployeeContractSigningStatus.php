<?php

namespace App\Enums;

enum EmployeeContractSigningStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting signatures',
            self::Completed => 'Signed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
