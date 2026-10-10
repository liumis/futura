<?php

namespace Tests\Feature;

use App\Enums\CargoStatus;
use App\Filament\Admin\Pages\ReportsWarehouseOrders;
use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsWarehouseOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_warehouse_orders_report(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ReportsWarehouseOrders::class)
            ->assertOk()
            ->assertSee('Warehouse orders')
            ->assertSee('From')
            ->assertSee('Until')
            ->assertSee('Supplier');
    }

    public function test_report_lists_matching_cargos_and_excludes_out_of_range(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::query()->create(['name' => 'Nordic Mill']);
        $other = Supplier::query()->create(['name' => 'Other Mill']);
        $product = Product::factory()->create();

        $inPeriod = Cargo::query()->create([
            'supplier_id' => $supplier->id,
            'status' => CargoStatus::Shipped,
            'date_shipped' => now()->toDateString(),
            'estimated_arrival' => now()->addWeeks(2)->toDateString(),
            'shipping_cost' => 25,
            'additional_cost' => 0,
        ]);
        CargoItem::query()->create([
            'cargo_id' => $inPeriod->id,
            'product_id' => $product->id,
            'amount' => 4,
            'self_cost' => 10,
        ]);

        Cargo::query()->create([
            'supplier_id' => $other->id,
            'status' => CargoStatus::Ordered,
            'date_shipped' => now()->subMonths(2)->toDateString(),
            'estimated_arrival' => now()->subMonth()->toDateString(),
            'shipping_cost' => 10,
            'additional_cost' => 0,
        ]);

        $page = Livewire::test(ReportsWarehouseOrders::class)
            ->assertOk()
            ->assertSee('Nordic Mill')
            ->instance();

        $totals = $page->totals();
        $this->assertSame(1, $totals['orders']);
        $this->assertSame(4, $totals['items']);
        $this->assertSame(25.0, $totals['shipping']);
    }

    public function test_supplier_filter_limits_rows(): void
    {
        $this->actingAsAdmin();

        $first = Supplier::query()->create(['name' => 'Alpha Supplier']);
        $second = Supplier::query()->create(['name' => 'Beta Supplier']);

        Cargo::query()->create([
            'supplier_id' => $first->id,
            'status' => CargoStatus::Received,
            'date_shipped' => now()->toDateString(),
            'estimated_arrival' => now()->addWeek()->toDateString(),
        ]);
        Cargo::query()->create([
            'supplier_id' => $second->id,
            'status' => CargoStatus::Received,
            'date_shipped' => now()->toDateString(),
            'estimated_arrival' => now()->addWeek()->toDateString(),
        ]);

        Livewire::test(ReportsWarehouseOrders::class)
            ->set('data.supplier_id', (string) $first->id)
            ->assertSee('Alpha Supplier');

        $totals = Livewire::test(ReportsWarehouseOrders::class)
            ->set('data.supplier_id', (string) $first->id)
            ->instance()
            ->totals();

        $this->assertSame(1, $totals['orders']);
    }

    private function actingAsAdmin(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Role::findOrCreate('admin');

        $admin = User::factory()->create([
            'email' => 'admin-warehouse-orders-report@example.com',
        ]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }
}
