<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockManualUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockManualUpdateLogger
{
    public static function log(
        Product $product,
        int $oldAmount,
        int $newAmount,
        ?string $comment = null,
    ): StockManualUpdate {
        if ($oldAmount === $newAmount) {
            throw new \InvalidArgumentException('Stock amount did not change.');
        }

        return StockManualUpdate::query()->create([
            'product_id' => $product->getKey(),
            'user_id' => auth()->id(),
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'comment' => filled($comment) ? trim($comment) : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Decrease product stock by $amount and log a manual write-off row.
     * Does not create a formal write-off document — that is done later on Manual write-off.
     */
    public static function writeOff(Product $product, int $amount, ?string $comment = null): StockManualUpdate
    {
        if ($amount < 1) {
            throw ValidationException::withMessages([
                'write_off' => 'Enter an amount of at least 1 to write off.',
            ]);
        }

        return DB::transaction(function () use ($product, $amount, $comment): StockManualUpdate {
            $locked = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->getKey());

            $oldAmount = (int) $locked->current_amount;

            if ($amount > $oldAmount) {
                throw ValidationException::withMessages([
                    'write_off' => 'Write-off exceeds current stock (maximum '.$oldAmount.').',
                ]);
            }

            $newAmount = $oldAmount - $amount;
            $locked->forceFill(['current_amount' => $newAmount])->save();

            return self::log($locked, $oldAmount, $newAmount, $comment);
        });
    }
}
