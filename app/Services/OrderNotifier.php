<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Support\Money;

class OrderNotifier
{
    /**
     * Notify opted-in users that a new order was created.
     *
     * @return int Number of users notified.
     */
    public static function created(Order $order): int
    {
        $order->loadMissing('user');

        $notification = new NewOrderNotification(
            orderId: (int) $order->id,
            customerLabel: self::customerLabel($order),
            totalFormatted: Money::format($order->amount),
            statusLabel: $order->status?->getLabel() ?? $order->status?->name ?? '—',
            url: OrderResource::getUrl('edit', ['record' => $order]),
        );

        $recipients = self::recipients();
        $notified = 0;

        foreach ($recipients as $user) {
            $user->notify($notification);
            $notified++;
        }

        return $notified;
    }

    private static function customerLabel(Order $order): string
    {
        $user = $order->user;

        if ($user === null) {
            return 'Unknown customer';
        }

        $company = trim((string) ($user->company_name ?? ''));
        if ($company !== '') {
            return $company;
        }

        $name = trim(implode(' ', array_filter([$user->name, $user->surname])));
        if ($name !== '') {
            return $name;
        }

        return (string) ($user->email ?? 'Unknown customer');
    }

    /**
     * Users who opted in to customer order notifications (excluding the creator).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private static function recipients(): \Illuminate\Support\Collection
    {
        $creatorId = auth()->id();

        return User::query()
            ->whereNotNull('notification_types')
            ->when($creatorId !== null, fn ($query) => $query->whereKeyNot($creatorId))
            ->get()
            ->filter(fn (User $user): bool => in_array(
                NotificationType::CustomerOrders->value,
                (array) ($user->notification_types ?? []),
                true,
            ))
            ->values();
    }
}
