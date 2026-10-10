<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Admin\Pages\ReportsOrdersChart;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\OrdersChartReportBuilder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsOrdersChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_orders_chart_report(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ReportsOrdersChart::class)
            ->assertOk()
            ->assertSee('Orders chart')
            ->assertSee('From')
            ->assertSee('Until')
            ->assertSee('Customers')
            ->assertSee('Customer country')
            ->assertSee('Meters')
            ->assertSee('Rolls');
    }

    public function test_builder_groups_sold_colors_by_collection(): void
    {
        $this->actingAsAdmin();
        Role::findOrCreate('customer');

        $customer = User::factory()->create([
            'company_name' => 'UAB Sold',
            'company_country' => 'Lithuania',
        ]);
        $customer->assignRole('customer');

        $collection = Collection::factory()->create(['name' => 'Agnella']);
        $white = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'White',
            'color_code' => 'AG101',
            'hex' => '#efece7',
        ]);
        $arctic = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Arctic',
            'color_code' => 'AG102',
            'hex' => '#e4e0d7',
        ]);

        $whiteProduct = Product::factory()->create([
            'color_id' => $white->id,
            'name' => '30',
        ]);
        $arcticProduct = Product::factory()->create([
            'color_id' => $arctic->id,
            'name' => '20',
        ]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => OrderStatus::Completed,
            'order_date' => now(),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $whiteProduct->id,
            'amount' => 2,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $arcticProduct->id,
            'amount' => 3,
        ]);

        Order::factory()->create([
            'user_id' => $customer->id,
            'status' => OrderStatus::Draft,
            'order_date' => now(),
        ]);

        $meters = OrdersChartReportBuilder::build(
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
            OrdersChartReportBuilder::METRIC_METERS,
        );

        $this->assertSame(1, $meters['orders']);
        $this->assertSame(2, $meters['colors']);
        $byColor = collect($meters['collections'][0]['colors'])->keyBy('label');
        $this->assertSame(60.0, $byColor['White']['stock']);
        $this->assertSame(60.0, $byColor['Arctic']['stock']);
        $this->assertSame('#EFECE7', $byColor['White']['hex']);
        $this->assertSame('#E4E0D7', $byColor['Arctic']['hex']);
        $this->assertSame(120.0, $meters['grand_total']);

        $rolls = OrdersChartReportBuilder::build(
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
            OrdersChartReportBuilder::METRIC_ROLLS,
        );
        $byRolls = collect($rolls['collections'][0]['colors'])->keyBy('label');
        $this->assertSame(2.0, $byRolls['White']['stock']);
        $this->assertSame(3.0, $byRolls['Arctic']['stock']);
    }

    public function test_filters_by_customer_and_country(): void
    {
        $this->actingAsAdmin();
        Role::findOrCreate('customer');

        $lt = User::factory()->create([
            'company_name' => 'LT Customer',
            'company_country' => 'Lithuania',
        ]);
        $lt->assignRole('customer');
        $de = User::factory()->create([
            'company_name' => 'DE Customer',
            'company_country' => 'Germany',
        ]);
        $de->assignRole('customer');

        $collection = Collection::factory()->create(['name' => 'Ferrara']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Noir',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '10',
        ]);

        foreach ([$lt, $de] as $customer) {
            $order = Order::factory()->create([
                'user_id' => $customer->id,
                'status' => OrderStatus::Shipped,
                'order_date' => now(),
            ]);
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'amount' => 1,
            ]);
        }

        $byCountry = OrdersChartReportBuilder::build(
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
            OrdersChartReportBuilder::METRIC_ROLLS,
            [],
            ['Lithuania'],
        );
        $this->assertSame(1, $byCountry['orders']);
        $this->assertSame(1.0, $byCountry['grand_total']);

        $byCustomer = OrdersChartReportBuilder::build(
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
            OrdersChartReportBuilder::METRIC_ROLLS,
            [$de->id],
            [],
        );
        $this->assertSame(1, $byCustomer['orders']);
        $this->assertSame(1.0, $byCustomer['grand_total']);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-orders-chart@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
