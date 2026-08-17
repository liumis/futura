<?php

namespace App\Enums;

enum InvoiceLanguage: string
{
    case Lithuanian = 'lt';
    case English = 'en';

    public function label(): string
    {
        return match ($this) {
            self::Lithuanian => 'Lithuanian',
            self::English => 'English',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }

    public static function normalize(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::English;
    }
}
