<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockManualUpdate;

class StockManualUpdateLogger
{
    public static function log(Product $product, int $oldAmount, int $newAmount): StockManualUpdate
    {
        if ($oldAmount === $newAmount) {
            throw new \InvalidArgumentException('Stock amount did not change.');
        }

        return StockManualUpdate::query()->create([
            'product_id' => $product->getKey(),
            'user_id' => auth()->id(),
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'created_at' => now(),
        ]);
    }
}
