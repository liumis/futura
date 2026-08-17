<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TodoPriority: string implements HasLabel
{
    case High = 'high';
    case Low = 'low';
    case Regular = 'regular';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::High => 'High',
            self::Low => 'Low',
            self::Regular => 'Regular',
        };
    }
}
