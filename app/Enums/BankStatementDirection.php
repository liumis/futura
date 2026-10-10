<?php

namespace App\Enums;

enum BankStatementDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Income',
            self::Debit => 'Outcome',
        };
    }

    public function isIncome(): bool
    {
        return $this === self::Credit;
    }
}
