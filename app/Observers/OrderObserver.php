<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\Order;
use App\Services\ActivityLogger;

class OrderObserver
{
    public function created(Order $order): void
    {
        ActivityLogger::log(
            ActivityLogEvent::OrderCreated,
            "Order #{$order->id} created",
            $order,
        );
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged('status')) {
            $old = $order->getOriginal('status');
            $new = $order->status;
            ActivityLogger::log(
                ActivityLogEvent::OrderStatusChanged,
                'Order #'.$order->id.' status changed: '.$this->formatScalar($old).' → '.$this->formatScalar($new),
                $order,
                ['old' => $this->scalarForProps($old), 'new' => $this->scalarForProps($new)],
            );
        }

        if ($order->wasChanged('shipping_cost')) {
            ActivityLogger::log(
                ActivityLogEvent::OrderShippingCostChanged,
                'Order #'.$order->id.' shipping cost changed: '.$this->formatScalar($order->getOriginal('shipping_cost')).' → '.$this->formatScalar($order->shipping_cost),
                $order,
                [
                    'old' => $order->getOriginal('shipping_cost'),
                    'new' => $order->shipping_cost,
                ],
            );
        }

        if ($order->wasChanged('tracking_number')) {
            ActivityLogger::log(
                ActivityLogEvent::OrderTrackingChanged,
                'Order #'.$order->id.' tracking number changed: '.$this->formatScalar($order->getOriginal('tracking_number')).' → '.$this->formatScalar($order->tracking_number),
                $order,
                [
                    'old' => $order->getOriginal('tracking_number'),
                    'new' => $order->tracking_number,
                ],
            );
        }

        if ($order->wasChanged('order_date')) {
            ActivityLogger::log(
                ActivityLogEvent::OrderUpdated,
                'Order #'.$order->id.' order date changed',
                $order,
                [
                    'old' => $order->getOriginal('order_date'),
                    'new' => $order->order_date?->toIso8601String(),
                ],
            );
        }

        if ($order->wasChanged('user_id')) {
            ActivityLogger::log(
                ActivityLogEvent::OrderUpdated,
                'Order #'.$order->id.' customer (user) changed',
                $order,
                [
                    'old_user_id' => $order->getOriginal('user_id'),
                    'new_user_id' => $order->user_id,
                ],
            );
        }

        if ($order->wasChanged('name')) {
            ActivityLogger::log(
                ActivityLogEvent::OrderUpdated,
                'Order #'.$order->id.' name field changed',
                $order,
                [
                    'old' => $order->getOriginal('name'),
                    'new' => $order->name,
                ],
            );
        }

        $ignored = [
            'status',
            'shipping_cost',
            'tracking_number',
            'order_date',
            'user_id',
            'name',
            'amount',
            'updated_at',
            'created_at',
        ];
        $changedKeys = array_keys($order->getChanges());
        $other = array_values(array_diff($changedKeys, $ignored));
        if ($other !== []) {
            ActivityLogger::log(
                ActivityLogEvent::OrderUpdated,
                'Order #'.$order->id.' updated',
                $order,
                ['attributes' => $order->only($other)],
            );
        }
    }

    public function deleted(Order $order): void
    {
        ActivityLogger::log(
            ActivityLogEvent::OrderDeleted,
            "Order #{$order->id} deleted",
            null,
            ['deleted_order_id' => $order->getKey()],
        );
    }

    private function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    private function scalarForProps(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}
