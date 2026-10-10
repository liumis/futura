<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CargoStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Shipped = 'shipped';
    case Confirmed = 'confirmed';
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
            self::Confirmed => 'Confirmed',
            self::InCustoms => 'In customs',
            self::PayImportFee => 'Pay import fee',
            self::ImportFeePayed => 'Import fee payed',
            self::Received => 'Received',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ordered => 'info',
            self::Shipped => 'primary',
            self::Confirmed => 'success',
            self::InCustoms => 'warning',
            self::PayImportFee => 'danger',
            self::ImportFeePayed => 'warning',
            self::Received => 'success',
        };
    }

    public function label(): string
    {
        return $this->getLabel() ?? $this->name;
    }

    /**
     * Customs documents section is available once the order is Confirmed or later.
     */
    public function allowsCustomsDocuments(): bool
    {
        return match ($this) {
            self::Confirmed,
            self::InCustoms,
            self::PayImportFee,
            self::ImportFeePayed,
            self::Received => true,
            default => false,
        };
    }

    public static function tryFromMixed(mixed $status): ?self
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
