<?php

namespace App\Services;

use App\Models\Cargo;

class CargoCostCalculator
{
    public static function importTaxesFor(Cargo $cargo): float
    {
        $cargo->loadMissing(['cargoItems', 'importTax']);

        $withoutShipping = 0.0;

        foreach ($cargo->cargoItems as $item) {
            if ($item->amount <= 0) {
                continue;
            }

            $withoutShipping += $item->amount * (float) $item->self_cost;
        }

        $withShipping = $withoutShipping + (float) $cargo->shipping_cost;
        $rate = (float) ($cargo->importTax?->rate ?? 0);

        return round($withShipping * $rate / 100, 2);
    }

    public static function totalItemQuantity(Cargo $cargo): int
    {
        $cargo->loadMissing('cargoItems');

        return (int) $cargo->cargoItems
            ->filter(fn ($item): bool => $item->amount > 0)
            ->sum('amount');
    }

    public static function importTaxPerUnit(Cargo $cargo): float
    {
        $totalQuantity = self::totalItemQuantity($cargo);

        if ($totalQuantity <= 0) {
            return 0.0;
        }

        return round(self::importTaxesFor($cargo) / $totalQuantity, 4);
    }

    public static function overheadPerUnit(Cargo $cargo): float
    {
        $totalQuantity = self::totalItemQuantity($cargo);

        if ($totalQuantity <= 0) {
            return 0.0;
        }

        $overheadTotal = self::importTaxesFor($cargo) + (float) $cargo->additional_cost;

        return round($overheadTotal / $totalQuantity, 4);
    }

    public static function allocatedUnitCost(Cargo $cargo, float $baseUnitCost): float
    {
        return round($baseUnitCost + self::overheadPerUnit($cargo), 2);
    }
}
