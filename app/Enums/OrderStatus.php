<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Placed = 'placed';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Shipped = 'shipped';
    case Completed = 'completed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Placed => 'Placed',
            self::Pending => 'Pending',
            self::Approved => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Shipped => 'Shipped',
            self::Completed => 'Completed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Placed => 'info',
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Shipped => 'info',
            self::Completed => 'success',
        };
    }

    public function label(): string
    {
        return $this->getLabel() ?? $this->name;
    }

    public function reservesStock(): bool
    {
        return match ($this) {
            self::Placed, self::Approved, self::Shipped, self::Completed => true,
            default => false,
        };
    }

    public function isCustomerEditable(): bool
    {
        return $this === self::Draft || $this === self::Pending;
    }

    public static function fromMixed(mixed $status): ?self
    {
        if ($status instanceof self) {
            return $status;
        }

        if ($status === null || $status === '') {
            return null;
        }

        return self::tryFrom((string) $status);
    }
}
