<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderListDoubleClickCellEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_list_marks_customer_and_date_for_double_click_edit(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create();

        $html = Livewire::test(ListOrders::class)->html();

        $this->assertStringContainsString('data-ss-cell-field="customer_name"', $html);
        $this->assertStringContainsString('data-ss-cell-field="order_date"', $html);
        $this->assertStringContainsString('data-ss-record-key="'.$order->id.'"', $html);
    }

    public function test_double_click_edit_updates_customer_company_name(): void
    {
        $this->actingAsAdmin();

        Role::findOrCreate('customer');
        $customer = User::factory()->create(['company_name' => 'Old Co']);
        $customer->assignRole('customer');

        $order = Order::factory()->create(['user_id' => $customer->id]);

        Livewire::test(ListOrders::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => 'New Co Name',
            ], arguments: [
                'recordKey' => (string) $order->id,
                'field' => 'customer_name',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('New Co Name', $customer->fresh()?->company_name);
    }

    public function test_double_click_edit_updates_order_date(): void
    {
        $this->actingAsAdmin();

        $order = Order::factory()->create([
            'order_date' => '2026-01-15',
        ]);

        Livewire::test(ListOrders::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => '2026-10-20',
            ], arguments: [
                'recordKey' => (string) $order->id,
                'field' => 'order_date',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('2026-10-20', $order->fresh()?->order_date?->toDateString());
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');

        $admin = User::factory()->create(['email' => 'admin-order-cell@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
