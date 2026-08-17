<?php

namespace App\Enums;

enum LeaveRequestStatus: string
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case CancellationPending = 'cancellation_pending';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Confirmed => 'Confirmed',
            self::CancellationPending => 'Cancellation pending',
            self::Canceled => 'Canceled',
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
