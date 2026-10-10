<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\Pages\EditOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderSummaryModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_order_has_summary_action_and_view_renders_items_table(): void
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('customer');

        $admin = User::factory()->create(['email' => 'admin-order-summary@example.com']);
        $admin->assignRole('admin');

        $customer = User::factory()->create(['company_name' => 'Summary Customer Co']);
        $customer->assignRole('customer');

        $product = Product::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => OrderStatus::Placed,
            'shipping_cost' => 12.5,
            'amount' => 100,
            'tracking_number' => 'TRACK-99',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'amount' => 3,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertActionExists('summary')
            ->assertSee('Summary')
            ->mountAction('summary')
            ->assertActionMounted('summary');

        $html = view('filament.admin.components.order-items-quick-view', [
            'order' => $order->fresh([
                'orderItems.product.productType',
                'orderItems.product.color.collection',
                'user',
                'package',
                'invoices',
            ]),
        ])->render();

        $this->assertStringContainsString('Summary Customer Co', $html);
        $this->assertStringContainsString('TRACK-99', $html);
        $this->assertStringContainsString('Shipping', $html);
        $this->assertStringContainsString('Product code', $html);
        $this->assertStringContainsString('Unit price', $html);
        $this->assertStringContainsString('€12.50', $html);
    }
}
