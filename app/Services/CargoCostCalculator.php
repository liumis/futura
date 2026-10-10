<?php

namespace App\Services;

use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\Product;

class CargoCostCalculator
{
    public static function importTaxesFor(Cargo $cargo): float
    {
        $cargo->loadMissing(['cargoItems.product.productType', 'importTax']);

        $withShipping = self::fullCostWithShipping($cargo);
        $rate = (float) ($cargo->importTax?->rate ?? 0);

        return round($withShipping * $rate / 100, 2);
    }

    public static function goodsValueTotal(Cargo $cargo): float
    {
        $cargo->loadMissing(['cargoItems.product.productType']);

        $total = 0.0;

        foreach ($cargo->cargoItems as $item) {
            if ($item->amount <= 0) {
                continue;
            }

            $total += $item->goodsValue();
        }

        return round($total, 2);
    }

    public static function fullCostWithShipping(Cargo $cargo): float
    {
        return round(
            self::goodsValueTotal($cargo)
            + (float) $cargo->shipping_cost
            + (float) $cargo->additional_cost,
            2,
        );
    }

    public static function overheadTotal(Cargo $cargo): float
    {
        return round(
            (float) $cargo->shipping_cost
            + (float) $cargo->additional_cost
            + self::importTaxesFor($cargo),
            2,
        );
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

        return round(self::overheadTotal($cargo) / $totalQuantity, 4);
    }

    public static function itemOverheadTotal(Cargo $cargo, CargoItem $item): float
    {
        if ($item->amount <= 0) {
            return 0.0;
        }

        $overhead = self::overheadTotal($cargo);

        if ($overhead <= 0) {
            return 0.0;
        }

        $goodsTotal = self::goodsValueTotal($cargo);

        if ($goodsTotal > 0) {
            return round($overhead * ($item->goodsValue() / $goodsTotal), 2);
        }

        $qtyTotal = self::totalItemQuantity($cargo);

        if ($qtyTotal <= 0) {
            return 0.0;
        }

        return round($overhead * ((int) $item->amount / $qtyTotal), 2);
    }

    public static function itemOverheadPerUnit(Cargo $cargo, CargoItem $item): float
    {
        if ($item->amount <= 0) {
            return 0.0;
        }

        return round(self::itemOverheadTotal($cargo, $item) / (int) $item->amount, 4);
    }

    public static function allocatedUnitCost(Cargo $cargo, float $baseUnitCost): float
    {
        return round($baseUnitCost + self::overheadPerUnit($cargo), 2);
    }

    public static function landedUnitCost(Cargo $cargo, CargoItem $item): float
    {
        return round($item->unitBaseCost() + self::itemOverheadPerUnit($cargo, $item), 2);
    }

    /**
     * Landed cost in the same unit as products.cost (per meter for leather, per piece otherwise).
     */
    public static function landedStockUnitCost(Cargo $cargo, CargoItem $item): float
    {
        $item->loadMissing('product.productType');
        $landedPerOrderedUnit = self::landedUnitCost($cargo, $item);
        $meters = $item->product instanceof Product ? $item->product->sizeMeters() : null;

        if ($meters !== null && $meters > 0) {
            return round($landedPerOrderedUnit / $meters, 2);
        }

        return $landedPerOrderedUnit;
    }

    public static function incomingStockQuantity(CargoItem $item): float
    {
        $item->loadMissing('product.productType');
        $meters = $item->product instanceof Product ? $item->product->sizeMeters() : null;

        if ($meters !== null && $meters > 0) {
            return (float) $item->amount * $meters;
        }

        return (float) $item->amount;
    }

    public static function currentStockQuantity(Product $product): float
    {
        $product->loadMissing('productType');

        if ($product->isCatalog() || $product->sizeMeters() === null) {
            return (float) $product->current_amount;
        }

        return $product->stockMeters();
    }

    public static function mergeAverageCost(float $oldCost, float $oldQty, float $newCost, float $newQty): float
    {
        $oldQty = max(0.0, $oldQty);
        $newQty = max(0.0, $newQty);

        if ($newQty <= 0) {
            return round($oldCost, 2);
        }

        if ($oldQty <= 0) {
            return round($newCost, 2);
        }

        return round((($oldCost * $oldQty) + ($newCost * $newQty)) / ($oldQty + $newQty), 2);
    }
}
