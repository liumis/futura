<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Product;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\LowStockNotification;

class LowStockNotifier
{
    /**
     * Notify eligible users about products below the low stock alert limit.
     *
     * @return int Number of users notified.
     */
    public static function run(): int
    {
        $limit = SystemSetting::instance()->lowStockAlertLimit();

        if ($limit <= 0) {
            return 0;
        }

        $productCount = Product::query()->belowStockMeterLimit($limit)->count();

        if ($productCount === 0) {
            return 0;
        }

        $recipients = self::recipients();
        $notified = 0;

        foreach ($recipients as $user) {
            if (self::hasActiveLowStockNotification($user)) {
                continue;
            }

            $user->notify(new LowStockNotification($productCount, $limit));
            $notified++;
        }

        return $notified;
    }

    /**
     * Users who opted in to low stock notifications.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private static function recipients(): \Illuminate\Support\Collection
    {
        return User::query()
            ->whereNotNull('notification_types')
            ->get()
            ->filter(fn (User $user): bool => in_array(
                NotificationType::LowStock->value,
                (array) ($user->notification_types ?? []),
                true,
            ))
            ->values();
    }

    private static function hasActiveLowStockNotification(User $user): bool
    {
        return $user->unreadNotifications()
            ->where('type', LowStockNotification::class)
            ->exists();
    }
}
