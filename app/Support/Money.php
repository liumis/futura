<?php

namespace App\Support;

class Money
{
    public static function currency(): string
    {
        return (string) config('money.currency', 'EUR');
    }

    public static function symbol(): string
    {
        return (string) config('money.symbol', '€');
    }

    public static function prefix(): string
    {
        return self::symbol();
    }

    public static function format(float|string|null $amount, int $decimals = 2, ?string $currency = null): string
    {
        $formatted = number_format((float) ($amount ?? 0), $decimals, '.', ' ');
        $code = strtoupper(trim((string) ($currency ?: self::currency())));

        if ($code === '' || $code === strtoupper(self::currency())) {
            return self::symbol().$formatted;
        }

        return $code.' '.$formatted;
    }
}
