<?php

namespace App\Enums;

enum DocumentConfirmationAudience: string
{
    case None = 'none';
    case AllActiveEmployees = 'all_active_employees';
    case SpecificUsers = 'specific_users';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::AllActiveEmployees => 'All active employees',
            self::SpecificUsers => 'Specific users',
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
