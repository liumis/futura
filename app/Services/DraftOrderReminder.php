<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Filament\Customer\Resources\Orders\OrderResource as CustomerOrderResource;
use App\Models\LtHoliday;
use App\Models\Order;
use App\Notifications\DraftOrderReminderNotification;
use App\Support\Money;
use Illuminate\Support\Carbon;

class DraftOrderReminder
{
    /**
     * Email customers about draft orders on Lithuanian working days.
     *
     * @return int Number of reminder emails queued/sent.
     */
    public static function run(?Carbon $date = null): int
    {
        $date = ($date ?? now())->copy()->startOfDay();

        if (! self::isWorkingDay($date)) {
            return 0;
        }

        $orders = Order::query()
            ->with('user')
            ->where('status', OrderStatus::Draft)
            ->whereNotNull('user_id')
            ->get();

        $sent = 0;

        foreach ($orders as $order) {
            $user = $order->user;

            if ($user === null || blank($user->email)) {
                continue;
            }

            $user->notify(new DraftOrderReminderNotification(
                orderId: (int) $order->id,
                orderUrl: CustomerOrderResource::getUrl('edit', ['record' => $order], panel: 'customer'),
                totalFormatted: Money::format($order->amount),
            ));

            $sent++;
        }

        return $sent;
    }

    public static function isWorkingDay(Carbon $date): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        return ! LtHoliday::isHoliday($date);
    }
}
