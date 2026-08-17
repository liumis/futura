<?php

namespace App\Enums;

enum EmployeeNpdType: string
{
    case None = 'none';
    case Standard = 'standard';
    case Disability0To25 = 'disability_0_25';
    case Disability30To55 = 'disability_30_55';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No NPD',
            self::Standard => 'Standard NPD',
            self::Disability0To25 => 'Disability / participation 0–25% (NPD €1,127)',
            self::Disability30To55 => 'Disability / participation 30–55% (NPD €1,057)',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
