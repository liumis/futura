<?php

namespace App\Enums;

enum BusinessTripPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partly = 'partly';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Partly => 'Partly paid',
            self::Paid => 'Paid',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
