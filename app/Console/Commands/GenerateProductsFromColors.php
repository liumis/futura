<?php

namespace App\Console\Commands;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductType;
use Illuminate\Console\Command;

class GenerateProductsFromColors extends Command
{
    protected $signature = 'products:generate-from-colors {amount=20 : Product size and stock amount}';

    protected $description = 'Create one product per color with generated product codes';

    public function handle(): int
    {
        $amount = (string) $this->argument('amount');
        $created = 0;
        $updated = 0;
        $leatherTypeId = ProductType::artificialLeatherId();

        if ($leatherTypeId === null) {
            $this->error('Product type "Artificial leather" is missing. Run migrations first.');

            return self::FAILURE;
        }

        Color::query()
            ->with('collection')
            ->orderBy('collection_id')
            ->orderBy('color_code')
            ->each(function (Color $color) use ($amount, $leatherTypeId, &$created, &$updated): void {
                $productCode = Product::generateProductCode($color, $amount);

                $product = Product::query()->updateOrCreate(
                    [
                        'color_id' => $color->id,
                        'name' => $amount,
                    ],
                    [
                        'product_type_id' => $leatherTypeId,
                        'product_code' => $productCode,
                        'current_amount' => (int) $amount,
                    ],
                );

                if ($product->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            });

        $this->info("Products ready: {$created} created, {$updated} updated (amount {$amount}).");

        return self::SUCCESS;
    }
}
