<?php

namespace Tests\Unit;

use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Services\OrderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
    }

    public function test_notifies_admins_with_customer_orders_preference_when_pending(): void
    {
        Notification::fake();

        $recipient = User::factory()->create([
            'notification_types' => [NotificationType::CustomerOrders->value],
        ]);
        $recipient->assignRole('admin');

        $ignored = User::factory()->create([
            'notification_types' => [],
        ]);
        $ignored->assignRole('admin');

        $order = Order::factory()->create([
            'status' => OrderStatus::Pending,
            'amount' => 120.50,
        ]);

        $this->assertSame(1, OrderNotifier::created($order));

        Notification::assertSentTo($recipient, NewOrderNotification::class);
        Notification::assertNotSentTo($ignored, NewOrderNotification::class);
    }

    public function test_does_not_notify_for_draft_orders(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'notification_types' => [NotificationType::CustomerOrders->value],
        ]);
        $admin->assignRole('admin');

        $order = Order::factory()->create([
            'status' => OrderStatus::Draft,
        ]);

        $this->assertSame(0, OrderNotifier::created($order));
        Notification::assertNothingSent();
    }

    public function test_observer_notifies_when_status_becomes_pending(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'notification_types' => [NotificationType::CustomerOrders->value],
        ]);
        $admin->assignRole('admin');

        $order = Order::factory()->create([
            'status' => OrderStatus::Draft,
        ]);

        $order->update(['status' => OrderStatus::Pending]);

        Notification::assertSentTo($admin, NewOrderNotification::class);
    }
}
