<?php

namespace Tests\Unit;

use App\Models\Collection;
use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Enums\OrderStatus;
use App\Services\ColorReorderPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ColorReorderPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ColorReorderPolicy::clearCache();
    }

    public function test_matches_collection_plus_code_plus_name(): void
    {
        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '121',
            'color_name' => 'BLACK',
            'recommended_stock' => 200,
            'default_order' => 10,
        ]);

        $rule = ColorReorderPolicy::findStockRule($color);

        $this->assertNotNull($rule);
        $this->assertSame('AGNONA121BLACK', $rule['id']);
        $this->assertSame(1100, (int) $rule['limit']);
    }

    public function test_matches_abbreviated_vinyl_color_name(): void
    {
        $collection = Collection::factory()->create(['name' => 'Palermo']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '5555',
            'color_name' => 'BLACK',
            'recommended_stock' => 200,
            'default_order' => 10,
        ]);

        $rule = ColorReorderPolicy::findStockRule($color);

        $this->assertNotNull($rule);
        $this->assertSame('PALERMO5555BL', $rule['id']);
        $this->assertSame(43000, (int) $rule['limit']);
    }

    public function test_apply_stock_rules_sets_recommended_and_default_order(): void
    {
        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '121',
            'color_name' => 'BLACK',
            'recommended_stock' => 200,
            'default_order' => 10,
        ]);

        $stats = ColorReorderPolicy::applyStockRules();

        $color->refresh();
        $this->assertSame(1, $stats['matched']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1100, (int) $color->recommended_stock);
        // max(moq=300, orderUpTo=1575) / roll=25 → ceil(1575/25)=63
        $this->assertSame(63, (int) $color->default_order);
    }

    public function test_nomark_colors_are_zeroed(): void
    {
        $collection = Collection::factory()->create(['name' => 'Palermo']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '3333',
            'color_name' => 'ONEOFF',
            'recommended_stock' => 200,
            'default_order' => 10,
        ]);

        $this->assertTrue(ColorReorderPolicy::isNoMark($color));

        ColorReorderPolicy::applyStockRules();
        $color->refresh();

        $this->assertSame(0, (int) $color->recommended_stock);
        $this->assertSame(0, (int) $color->default_order);
    }

    public function test_compute_from_sales_matches_policy_formula(): void
    {
        // avg=156, cls=A, roll=25, F → limit = max(100, ceil(156*7/25)*25)=1100
        $targets = ColorReorderPolicy::computeFromSales(156, 'A', 25, 'F');

        $this->assertSame(1100, $targets['recommended_stock']);
        $this->assertSame(1575.0, $targets['orderUpTo']);
        $this->assertSame(63, $targets['default_order']);
    }

    public function test_order_observer_silently_refreshes_on_stock_change(): void
    {
        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '121',
            'color_name' => 'BLACK',
            'recommended_stock' => 200,
            'default_order' => 10,
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '25',
            'current_amount' => 10,
        ]);

        $order = Order::factory()->create(['status' => OrderStatus::Draft]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'amount' => 1,
        ]);

        $order->update(['status' => OrderStatus::Placed]);

        $color->refresh();
        // Live recompute with no trailing sales → minM floor 100, cls C, F roll 25
        $this->assertSame(100, (int) $color->recommended_stock);
        $this->assertGreaterThan(0, (int) $color->default_order);
    }
}
