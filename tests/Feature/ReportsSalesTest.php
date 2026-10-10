<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Admin\Pages\ReportsSales;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\SalesReportBuilder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsSalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_sales_report_with_filters(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ReportsSales::class)
            ->assertOk()
            ->assertSee('Sales matrix')
            ->assertSee('Total sum')
            ->assertSee('Meters')
            ->assertSee('From')
            ->assertSee('Until')
            ->assertSee('No sales in this period.');
    }

    public function test_matrix_shows_customer_collection_sum_and_meters(): void
    {
        $this->actingAsAdmin();
        [$customer] = $this->seedSale(
            company: 'UAB Vikme',
            code: 'VIKME',
            collectionName: 'Ferrara',
            price: 10,
            size: '20',
            quantity: 2,
            status: OrderStatus::Completed,
            orderDate: now(),
        );

        Livewire::test(ReportsSales::class)
            ->assertOk()
            ->assertSee('UAB Vikme')
            ->assertSee('VIKME')
            ->assertSee('Ferrara')
            ->assertSee('400.00')
            ->assertDontSee('40.00');

        Livewire::test(ReportsSales::class)
            ->set('data.metric', SalesReportBuilder::METRIC_METERS)
            ->assertSee('UAB Vikme')
            ->assertSee('40.00')
            ->assertDontSee('400.00');

        $this->assertSame('VIKME', SalesReportBuilder::customerCode($customer));
    }

    public function test_date_filter_and_rejected_orders_are_excluded(): void
    {
        $this->actingAsAdmin();
        $this->seedSale(
            company: 'In Period UAB',
            code: 'INPER',
            collectionName: 'Palermo',
            price: 5,
            size: '10',
            quantity: 1,
            status: OrderStatus::Shipped,
            orderDate: now(),
        );
        $this->seedSale(
            company: 'Old Sale UAB',
            code: 'OLD',
            collectionName: 'Palermo',
            price: 5,
            size: '10',
            quantity: 1,
            status: OrderStatus::Completed,
            orderDate: now()->subYear(),
        );
        $this->seedSale(
            company: 'Rejected UAB',
            code: 'REJ',
            collectionName: 'Palermo',
            price: 5,
            size: '10',
            quantity: 1,
            status: OrderStatus::Rejected,
            orderDate: now(),
        );

        Livewire::test(ReportsSales::class)
            ->assertSee('In Period UAB')
            ->assertDontSee('Old Sale UAB')
            ->assertDontSee('Rejected UAB');
    }

    /**
     * @return array{0: User, 1: Order}
     */
    private function seedSale(
        string $company,
        string $code,
        string $collectionName,
        float $price,
        string $size,
        int $quantity,
        OrderStatus $status,
        mixed $orderDate,
    ): array {
        $customer = User::factory()->create([
            'company_name' => $company,
            'company_code' => $code,
        ]);
        $collection = Collection::factory()->create([
            'name' => $collectionName,
            'price' => $price,
        ]);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => $size,
        ]);
        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'status' => $status,
            'order_date' => $orderDate,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'amount' => $quantity,
        ]);

        return [$customer, $order];
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-sales-report@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
