<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ImportTaxPaymentStatus: string implements HasColor, HasLabel
{
    case Unpaid = 'unpaid';
    case Payed = 'payed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Payed => 'Payed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Unpaid => 'warning',
            self::Payed => 'success',
        };
    }

    public function label(): string
    {
        return $this->getLabel() ?? $this->name;
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
