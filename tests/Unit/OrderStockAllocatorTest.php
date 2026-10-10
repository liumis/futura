<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\OrderStockAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStockAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_placing_an_order_reduces_stock(): void
    {
        $product = Product::factory()->create(['current_amount' => 10]);
        $order = $this->orderWithItem($product, 4, OrderStatus::Draft);

        $order->update(['status' => OrderStatus::Placed]);

        $this->assertSame(6, (int) $product->fresh()->current_amount);
        $this->assertTrue($order->fresh()->stock_reserved);
    }

    public function test_rejecting_a_placed_order_restores_stock(): void
    {
        $product = Product::factory()->create(['current_amount' => 10]);
        $order = $this->orderWithItem($product, 4, OrderStatus::Draft);

        $order->update(['status' => OrderStatus::Placed]);
        $order->update(['status' => OrderStatus::Rejected]);

        $this->assertSame(10, (int) $product->fresh()->current_amount);
        $this->assertFalse($order->fresh()->stock_reserved);
    }

    public function test_rejecting_a_confirmed_order_restores_stock(): void
    {
        $product = Product::factory()->create(['current_amount' => 10]);
        $order = $this->orderWithItem($product, 4, OrderStatus::Draft);

        $order->update(['status' => OrderStatus::Approved]);
        $this->assertSame(6, (int) $product->fresh()->current_amount);

        $order->update(['status' => OrderStatus::Rejected]);

        $this->assertSame(10, (int) $product->fresh()->current_amount);
        $this->assertFalse($order->fresh()->stock_reserved);
    }

    public function test_confirming_after_placed_does_not_reduce_stock_again(): void
    {
        $product = Product::factory()->create(['current_amount' => 10]);
        $order = $this->orderWithItem($product, 4, OrderStatus::Draft);

        $order->update(['status' => OrderStatus::Placed]);
        $order->update(['status' => OrderStatus::Approved]);

        $this->assertSame(6, (int) $product->fresh()->current_amount);
        $this->assertTrue($order->fresh()->stock_reserved);
    }

    public function test_pending_does_not_reserve_stock(): void
    {
        $product = Product::factory()->create(['current_amount' => 10]);
        $order = $this->orderWithItem($product, 4, OrderStatus::Draft);

        $order->update(['status' => OrderStatus::Pending]);

        $this->assertSame(10, (int) $product->fresh()->current_amount);
        $this->assertFalse($order->fresh()->stock_reserved);
    }

    public function test_creating_as_placed_reserves_stock_after_items_exist(): void
    {
        $product = Product::factory()->create(['current_amount' => 10]);
        $order = $this->orderWithItem($product, 4, OrderStatus::Placed);

        OrderStockAllocator::applyCreated($order->fresh(['orderItems']));

        $this->assertSame(6, (int) $product->fresh()->current_amount);
        $this->assertTrue($order->fresh()->stock_reserved);
    }

    public function test_changing_line_quantities_on_a_placed_order_adjusts_stock(): void
    {
        $product = Product::factory()->create(['current_amount' => 10]);
        $order = $this->orderWithItem($product, 4, OrderStatus::Draft);

        $order->update(['status' => OrderStatus::Placed]);

        OrderResource::syncOrderItemsFromAmounts($order->fresh(['orderItems']), [
            $product->id => 7,
        ]);

        $this->assertSame(3, (int) $product->fresh()->current_amount);
    }

    public function test_deleting_a_placed_order_restores_stock(): void
    {
        $product = Product::factory()->create(['current_amount' => 10]);
        $order = $this->orderWithItem($product, 4, OrderStatus::Draft);

        $order->update(['status' => OrderStatus::Placed]);
        $order->delete();

        $this->assertSame(10, (int) $product->fresh()->current_amount);
    }

    public function test_existing_unreserved_confirmed_orders_do_not_take_stock_when_shipped(): void
    {
        $product = Product::factory()->create(['current_amount' => 10]);
        $order = $this->orderWithItem($product, 4, OrderStatus::Approved);

        $this->assertFalse($order->stock_reserved);

        $order->update(['status' => OrderStatus::Shipped]);

        $this->assertSame(10, (int) $product->fresh()->current_amount);
        $this->assertFalse($order->fresh()->stock_reserved);
    }

    public function test_status_labels_and_order(): void
    {
        $this->assertSame(
            ['draft', 'placed', 'pending', 'approved', 'rejected', 'shipped', 'completed'],
            array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::cases()),
        );
        $this->assertSame('Confirmed', OrderStatus::Approved->getLabel());
        $this->assertSame('Placed', OrderStatus::Placed->getLabel());
        $this->assertSame('Draft', OrderStatus::Draft->getLabel());
    }

    private function orderWithItem(Product $product, int $amount, OrderStatus $status): Order
    {
        $order = Order::factory()->create([
            'status' => $status,
            'stock_reserved' => false,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'amount' => $amount,
        ]);

        return $order->fresh(['orderItems']);
    }
}
