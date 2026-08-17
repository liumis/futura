<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CargoStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Shipped = 'shipped';
    case InCustoms = 'in_customs';
    case PayImportFee = 'pay_import_fee';
    case ImportFeePayed = 'import_fee_payed';
    case Received = 'received';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ordered => 'Ordered',
            self::Shipped => 'Shipped',
            self::InCustoms => 'In customs',
            self::PayImportFee => 'Pay import fee',
            self::ImportFeePayed => 'Import fee payed',
            self::Received => 'Received',
        };
    }

    public function label(): string
    {
        return $this->getLabel() ?? $this->name;
    }
}
