<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WarehouseImportStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Received = 'received';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Received => 'Received (imported)',
        };
    }

    public function label(): string
    {
        return $this->getLabel() ?? $this->name;
    }
}
