<?php

namespace App\Services;

class CollectionPricing
{
    public const CUSTOMS_RATE = 0.08;

    public const DEFAULT_AGENT_FEE = 0.1;

    public static function customs(?float $portPrice): ?float
    {
        if ($portPrice === null) {
            return null;
        }

        return round($portPrice * self::CUSTOMS_RATE, 2);
    }

    public static function costPrice(
        ?float $portPrice,
        ?float $agentFee = null,
        ?float $otherCost = null,
    ): ?float {
        if ($portPrice === null) {
            return null;
        }

        return round(
            $portPrice
            + (self::customs($portPrice) ?? 0)
            + ($agentFee ?? 0)
            + ($otherCost ?? 0),
            2,
        );
    }

    public static function sellPrice(?float $costPrice, ?float $markupPercent): ?float
    {
        if ($costPrice === null || $markupPercent === null) {
            return null;
        }

        return round($costPrice * (1 + ($markupPercent / 100)), 2);
    }

    /**
     * @return array{cost: float|null, price: float|null, customs: float|null}
     */
    public static function syncTotals(
        ?float $portPrice,
        ?float $agentFee,
        ?float $otherCost,
        ?float $markupPercent,
    ): array {
        $customs = self::customs($portPrice);
        $cost = self::costPrice($portPrice, $agentFee, $otherCost);
        $price = self::sellPrice($cost, $markupPercent);

        return [
            'customs' => $customs,
            'cost' => $cost,
            'price' => $price,
        ];
    }

    public static function formatAmount(?float $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        $formatted = fmod($amount, 1.0) === 0.0
            ? (string) (int) $amount
            : rtrim(rtrim(number_format($amount, 2, ',', ''), '0'), ',');

        return $formatted.' €';
    }

    public static function formatInput(?float $amount, bool $withEuro = false): string
    {
        if ($amount === null) {
            return '';
        }

        $formatted = fmod($amount, 1.0) === 0.0
            ? (string) (int) $amount
            : rtrim(rtrim(number_format($amount, 2, ',', ''), '0'), ',');

        return $withEuro ? $formatted.' €' : $formatted;
    }

    /**
     * @return float|null|false null when empty, false when invalid
     */
    public static function parseNullableNumber(?string $value): float|false|null
    {
        $raw = trim((string) $value);
        $raw = str_replace(["\u{00A0}", ' '], '', $raw);
        $raw = str_ireplace(['€', 'eur'], '', $raw);
        $raw = trim(str_replace(',', '.', $raw));

        if ($raw === '' || $raw === '—' || $raw === '-' || $raw === '–') {
            return null;
        }

        if (! is_numeric($raw) || (float) $raw < 0) {
            return false;
        }

        return round((float) $raw, 4);
    }
}
