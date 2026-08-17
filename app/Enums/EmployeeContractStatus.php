<?php

namespace App\Enums;

enum EmployeeContractStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Signed = 'signed';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ready => 'Ready',
            self::Signed => 'Signed',
            self::Inactive => 'Inactive',
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

