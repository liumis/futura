<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderStockAllocator
{
    public static function statusReservesStock(mixed $status): bool
    {
        return OrderStatus::fromMixed($status)?->reservesStock() ?? false;
    }

    public static function applyCreated(Order $order): void
    {
        self::applyStatusTransition($order, null, $order->status);
    }

    public static function applyStatusTransition(Order $order, mixed $fromStatus, mixed $toStatus): void
    {
        $fromHolds = self::statusReservesStock($fromStatus);
        $toHolds = self::statusReservesStock($toStatus);

        if ($toHolds === $fromHolds) {
            return;
        }

        if ($toHolds && ! $order->stock_reserved) {
            self::reserve($order);
            self::markReserved($order, true);

            return;
        }

        // Leaving a reserving status (e.g. Confirmed/Placed → Rejected) restores stock.
        if (! $toHolds && $order->stock_reserved) {
            self::release($order);
            self::markReserved($order, false);
        }
    }

    public static function releaseIfReserved(Order $order): void
    {
        if (! $order->stock_reserved) {
            return;
        }

        self::release($order);
        self::markReserved($order, false);
    }

    public static function reserve(Order $order): void
    {
        self::adjustStock($order, -1);
    }

    public static function release(Order $order): void
    {
        self::adjustStock($order, 1);
    }

    private static function markReserved(Order $order, bool $reserved): void
    {
        if ((bool) $order->stock_reserved === $reserved) {
            return;
        }

        Order::withoutEvents(function () use ($order, $reserved): void {
            Order::query()->whereKey($order->getKey())->update([
                'stock_reserved' => $reserved,
            ]);
        });

        $order->setAttribute('stock_reserved', $reserved);
        $order->syncOriginalAttribute('stock_reserved');
    }

    private static function adjustStock(Order $order, int $sign): void
    {
        if ($sign !== 1 && $sign !== -1) {
            return;
        }

        DB::transaction(function () use ($order, $sign): void {
            $order->loadMissing('orderItems');

            foreach ($order->orderItems as $item) {
                $qty = (int) $item->amount;

                if ($qty <= 0 || $item->product_id === null) {
                    continue;
                }

                $product = Product::query()
                    ->whereKey($item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($product === null) {
                    continue;
                }

                $next = (int) $product->current_amount + ($sign * $qty);
                $product->update(['current_amount' => max(0, $next)]);
            }
        });
    }
}
