<?php

namespace App\Enums;

enum EmployeeMonthlyPaymentStatus: string
{
    case Open = 'open';
    case Payed = 'payed';
    case Wrong = 'wrong';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Payed => 'Payed',
            self::Wrong => 'Cancelled',
        };
    }

    public function isLocked(): bool
    {
        return $this !== self::Open;
    }
}
