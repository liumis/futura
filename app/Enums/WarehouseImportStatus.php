<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WarehouseImportStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Received = 'received';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Received => 'In stock',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'danger',
            self::Received => 'success',
        };
    }

    public function label(): string
    {
        return $this->getLabel() ?? $this->name;
    }
}
