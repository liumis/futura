<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DraftOrderReminderNotification;
use App\Services\DraftOrderReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DraftOrderReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_reminder_for_draft_orders_on_working_days(): void
    {
        Notification::fake();

        $customer = User::factory()->create();
        Order::factory()->create([
            'user_id' => $customer->id,
            'status' => OrderStatus::Draft,
            'amount' => 50,
        ]);
        Order::factory()->create([
            'user_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'amount' => 75,
        ]);

        // A known weekday that is not a fixed LT public holiday.
        $date = Carbon::parse('2026-09-08')->startOfDay();
        $this->assertTrue(DraftOrderReminder::isWorkingDay($date));

        $sent = DraftOrderReminder::run($date);

        $this->assertSame(1, $sent);
        Notification::assertSentTo($customer, DraftOrderReminderNotification::class);
    }

    public function test_skips_weekends(): void
    {
        Notification::fake();

        $customer = User::factory()->create();
        Order::factory()->create([
            'user_id' => $customer->id,
            'status' => OrderStatus::Draft,
        ]);

        $saturday = Carbon::parse('2026-09-05')->startOfDay();
        $this->assertFalse(DraftOrderReminder::isWorkingDay($saturday));
        $this->assertSame(0, DraftOrderReminder::run($saturday));
        Notification::assertNothingSent();
    }
}
