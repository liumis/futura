<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Shipped = 'shipped';
    case Completed = 'completed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Shipped => 'Shipped',
            self::Completed => 'Completed',
        };
    }

    public function label(): string
    {
        return $this->getLabel() ?? $this->name;
    }
}
