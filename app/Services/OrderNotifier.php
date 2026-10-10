<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Support\Money;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

class OrderNotifier
{
    /**
     * Notify opted-in staff that a customer order was submitted (Pending).
     *
     * @return int Number of users notified.
     */
    public static function created(Order $order): int
    {
        $order->loadMissing('user');

        if (OrderStatus::fromMixed($order->status) !== OrderStatus::Pending) {
            return 0;
        }

        $notification = new NewOrderNotification(
            orderId: (int) $order->id,
            customerLabel: self::customerLabel($order),
            totalFormatted: Money::format($order->amount),
            statusLabel: $order->status?->getLabel() ?? $order->status?->name ?? '—',
            url: OrderResource::getUrl('edit', ['record' => $order]),
        );

        $notified = 0;

        foreach (self::recipients() as $user) {
            $user->notify($notification);
            $notified++;
        }

        return $notified;
    }

    /**
     * Notify when an order becomes Pending (e.g. customer submits a draft).
     */
    public static function submitted(Order $order): int
    {
        return self::created($order->fresh(['user']) ?? $order);
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
     * Admin users who opted in to customer order notifications (excluding the actor).
     *
     * @return Collection<int, User>
     */
    private static function recipients(): Collection
    {
        if (! Role::query()->where('name', 'admin')->where('guard_name', 'web')->exists()) {
            return collect();
        }

        $actorId = auth()->id();

        return User::query()
            ->role('admin')
            ->when($actorId !== null, fn ($query) => $query->whereKeyNot($actorId))
            ->get()
            ->filter(fn (User $user): bool => $user->wantsNotification(NotificationType::CustomerOrders))
            ->values();
    }
}
