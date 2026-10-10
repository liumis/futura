<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentSchedule: string implements HasColor, HasLabel
{
    case OneTime = 'one_time';
    case Periodical = 'periodical';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::OneTime => 'Single time',
            self::Periodical => 'Periodical',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::OneTime => 'gray',
            self::Periodical => 'info',
        };
    }

    public function label(): string
    {
        return $this->getLabel() ?? $this->name;
    }

    public function isPeriodical(): bool
    {
        return $this === self::Periodical;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $schedule): array => [$schedule->value => $schedule->label()])
            ->all();
    }
}
