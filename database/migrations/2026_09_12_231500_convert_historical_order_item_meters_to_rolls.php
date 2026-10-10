<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\HistoricalSalesOrderImporter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = HistoricalSalesOrderImporter::HISTORY_NAME_PREFIX;

        Order::query()
            ->where('name', 'like', $prefix.'%')
            ->with(['orderItems.product'])
            ->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    foreach ($order->orderItems as $item) {
                        $product = $item->product;
                        if (! $product instanceof Product) {
                            continue;
                        }

                        $size = $product->sizeMeters();
                        $meters = (int) $item->amount;
                        if ($size === null || $meters <= 0) {
                            continue;
                        }

                        // Already stored as rolls when amount is smaller than size.
                        if ($meters < $size) {
                            continue;
                        }

                        $rolls = $product->rollAmountFromMeters($meters);
                        if ($rolls === $meters || $rolls <= 0) {
                            continue;
                        }

                        OrderItem::query()->whereKey($item->getKey())->update([
                            'amount' => $rolls,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Irreversible without original meter quantities.
    }
};
