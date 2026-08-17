<?php

namespace App\Enums;

enum EmployeePaymentReportStatus: string
{
    case Created = 'created';
    case WaitingConfirmations = 'waiting_confirmations';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Report created',
            self::WaitingConfirmations => 'Waiting confirmations',
            self::Confirmed => 'Confirmed',
        };
    }
}
