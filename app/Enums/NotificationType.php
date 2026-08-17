<?php

namespace App\Enums;

enum NotificationType: string
{
    case CustomerOrders = 'customer_orders';
    case LowStock = 'low_stock';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::CustomerOrders => 'Customer orders',
            self::LowStock => 'Low stock',
            self::Personal => 'Personal',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
