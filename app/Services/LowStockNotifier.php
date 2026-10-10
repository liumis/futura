<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Color;
use App\Models\User;
use App\Notifications\LowStockNotification;
use Illuminate\Support\Collection;

class LowStockNotifier
{
    /**
     * Notify eligible users about colors below their recommended stock.
     *
     * @return int Number of users notified.
     */
    public static function run(): int
    {
        $colorCount = Color::query()->belowRecommendedStock()->count();

        if ($colorCount === 0) {
            return 0;
        }

        $recipients = self::recipients();
        $notified = 0;

        foreach ($recipients as $user) {
            if (self::hasActiveLowStockNotification($user)) {
                continue;
            }

            $user->notify(new LowStockNotification($colorCount));
            $notified++;
        }

        return $notified;
    }

    /**
     * Users who opted in to low stock notifications.
     *
     * @return Collection<int, User>
     */
    private static function recipients(): Collection
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
