<?php

namespace Tests\Unit;

use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAmountAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_amount_is_stock_plus_upcoming(): void
    {
        $product = Product::factory()->create(['current_amount' => 6]);

        $this->assertSame(6, OrderResource::availableOrderAmount($product, 0));
        $this->assertSame(14, OrderResource::availableOrderAmount($product, 8));
        $this->assertSame(10, OrderResource::availableOrderAmount($product, 0, 4));
    }

    public function test_clamp_caps_amount_at_stock_plus_upcoming(): void
    {
        $product = Product::factory()->create(['current_amount' => 6]);
        $other = Product::factory()->create(['current_amount' => 2]);

        $cargo = Cargo::query()->create([
            'status' => CargoStatus::Ordered,
            'date_shipped' => '2026-07-01',
            'estimated_arrival' => '2026-08-06',
        ]);
        CargoItem::query()->create([
            'cargo_id' => $cargo->id,
            'product_id' => $product->id,
            'amount' => 8,
        ]);

        $clamped = OrderResource::clampAmountsToAvailability([
            $product->id => 20,
            $other->id => 1,
        ]);

        $this->assertSame(14, $clamped[$product->id]);
        $this->assertSame(1, $clamped[$other->id]);
    }

    public function test_received_cargo_does_not_count_as_upcoming(): void
    {
        $product = Product::factory()->create(['current_amount' => 6]);

        $cargo = Cargo::query()->create([
            'status' => CargoStatus::Received,
            'date_shipped' => '2026-07-01',
            'estimated_arrival' => '2026-08-06',
        ]);
        CargoItem::query()->create([
            'cargo_id' => $cargo->id,
            'product_id' => $product->id,
            'amount' => 8,
        ]);

        $clamped = OrderResource::clampAmountsToAvailability([
            $product->id => 20,
        ]);

        $this->assertSame(6, $clamped[$product->id]);
    }
}
