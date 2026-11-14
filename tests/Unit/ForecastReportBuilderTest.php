<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ForecastReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForecastReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate(User::ROLE_CUSTOMER, 'web');
    }

    public function test_detects_bikinis_as_stable_customer(): void
    {
        $user = User::factory()->create([
            'company_name' => 'BIKINIS BROS S.A.',
            'is_stable_forecast' => false,
        ]);
        $user->assignRole(User::ROLE_CUSTOMER);

        $this->assertTrue(ForecastReportBuilder::isStableCustomer($user));
    }

    public function test_stable_floor_keeps_buy_suggestion_for_regular_color(): void
    {
        $customer = User::factory()->create([
            'company_name' => 'BIKINIS BROS S.A.',
            'is_stable_forecast' => true,
        ]);
        $customer->assignRole(User::ROLE_CUSTOMER);

        $collection = Collection::factory()->create(['name' => 'Argano']);
        $color = Color::factory()->create([
            'collection_id' => $collection->getKey(),
            'color_code' => '124',
            'color_name' => 'Steel',
            'recommended_stock' => 0,
            'default_order' => 0,
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->getKey(),
            'name' => '30',
            'current_amount' => 0,
        ]);

        // Two historical orders ~90 days apart, 20 rolls (= 600 m) each.
        $this->placeOrder($customer, $product, 20, now()->subDays(100)->toDateString());
        $this->placeOrder($customer, $product, 20, now()->subDays(10)->toDateString());

        $report = ForecastReportBuilder::build(
            lookbackDays: 90,
            horizonDays: 60,
            onlyNeedsOrder: true,
        );

        $row = collect($report['rows'])->firstWhere('color_id', $color->getKey());

        $this->assertNotNull($row);
        $this->assertTrue($row['stable']);
        $this->assertGreaterThan(0, $row['stable_floor_m']);
        $this->assertGreaterThan(0, $row['buy_m']);
        $this->assertGreaterThan(0, $row['buy_rolls']);
        $this->assertSame(['BIKINIS BROS S.A.'], collect($report['stable_customers'])->pluck('label')->all());
    }

    public function test_on_hand_stock_reduces_buy_quantity(): void
    {
        $customer = User::factory()->create([
            'company_name' => 'Other Customer UAB',
            'is_stable_forecast' => false,
        ]);
        $customer->assignRole(User::ROLE_CUSTOMER);

        $collection = Collection::factory()->create(['name' => 'Paloma']);
        $color = Color::factory()->create([
            'collection_id' => $collection->getKey(),
            'color_code' => '106',
            'color_name' => 'Starfish',
            'recommended_stock' => 0,
            'default_order' => 0,
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->getKey(),
            'name' => '30',
            'current_amount' => 40, // 1200 m on hand
        ]);

        $this->placeOrder($customer, $product, 10, now()->subDays(5)->toDateString()); // 300 m in 90d

        $report = ForecastReportBuilder::build(90, 60, onlyNeedsOrder: false);
        $row = collect($report['rows'])->firstWhere('color_id', $color->getKey());

        $this->assertNotNull($row);
        $this->assertSame(300.0, $row['sold_m']);
        $this->assertSame(0.0, $row['buy_m']);
        $this->assertGreaterThanOrEqual(1200.0, $row['on_hand_m']);
    }

    private function placeOrder(User $customer, Product $product, int $rolls, string $orderDate): void
    {
        $order = Order::query()->create([
            'user_id' => $customer->getKey(),
            'status' => OrderStatus::Completed,
            'order_date' => $orderDate,
            'amount' => 0,
            'shipping_cost' => 0,
            'stock_reserved' => false,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'amount' => $rolls,
        ]);
    }
}
