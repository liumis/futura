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

    public static function format(float|string|null $amount, int $decimals = 2): string
    {
        return self::symbol().number_format((float) ($amount ?? 0), $decimals, '.', '');
    }
}
