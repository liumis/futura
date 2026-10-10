<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\HistoricalSalesOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HistoricalSalesOrderImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_history_orders_without_touching_stock_and_creates_customers(): void
    {
        Role::findOrCreate(User::ROLE_CUSTOMER, 'web');

        $existing = User::factory()->create([
            'company_name' => 'SVETMAR D.O.O.',
            'email' => 'svetmar-existing@example.com',
        ]);
        $existing->assignRole(User::ROLE_CUSTOMER);

        $matchedProduct = Product::factory()->create([
            'name' => '30',
            'current_amount' => 500,
            'alternative_code' => 'AGNONA121BLACK30',
        ]);
        $otherProduct = Product::factory()->create([
            'name' => '30',
            'current_amount' => 200,
            'alternative_code' => 'BORGA102VANILLA30',
        ]);

        $path = storage_path('app/testing/historical-sales-mini.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'rows' => [
                [
                    'customer_code' => 'SVETMAR',
                    'customer' => 'Svetmar d.o.o.',
                    'category' => 'Artificial leather',
                    'month' => '2026-08',
                    'sales_date' => '2026-08-24',
                    'item' => 'AGNONA121BLACK30',
                    'quantity' => '30',
                    'unit' => 'm',
                    'cost_eur' => '192,00',
                    'sales_eur' => '207,00',
                ],
                [
                    'customer_code' => 'SVETMAR',
                    'customer' => 'Svetmar d.o.o.',
                    'category' => 'Artificial leather',
                    'month' => '2026-08',
                    'sales_date' => '2026-08-24',
                    'item' => 'BORGA102VANILLA30',
                    'quantity' => '60',
                    'unit' => 'm',
                    'cost_eur' => '300,00',
                    'sales_eur' => '414,00',
                ],
                [
                    'customer_code' => 'ATSTYLE',
                    'customer' => 'A & T Style ltd',
                    'category' => 'Artificial leather',
                    'month' => '2026-02',
                    'sales_date' => '2026-02-13',
                    'item' => 'AGNONA121BLACK30',
                    'quantity' => '30',
                    'unit' => 'm',
                    'cost_eur' => '142,14',
                    'sales_eur' => '207,00',
                ],
                [
                    'customer_code' => 'ATSTYLE',
                    'customer' => 'A & T Style ltd',
                    'category' => 'Catalogues',
                    'month' => '2025-10',
                    'sales_date' => '2025-10-16',
                    'item' => '/BORGA/CATALOGUES',
                    'quantity' => '45',
                    'unit' => 'pcs',
                    'cost_eur' => '0',
                    'sales_eur' => '121,50',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = app(HistoricalSalesOrderImporter::class)->import(path: $path);

        $this->assertSame(1, $result['customers_created']);
        $this->assertSame(1, $result['customers_matched']);
        $this->assertSame(2, $result['orders_created']);
        $this->assertSame(0, $result['orders_skipped']);
        $this->assertSame(3, $result['items_created']);
        $this->assertSame(1, $result['lines_skipped_no_product']);

        $this->assertSame(500, (int) $matchedProduct->fresh()->current_amount);
        $this->assertSame(200, (int) $otherProduct->fresh()->current_amount);

        $historyOrders = Order::query()->where('name', 'like', HistoricalSalesOrderImporter::HISTORY_NAME_PREFIX.'%')->get();
        $this->assertCount(2, $historyOrders);
        $this->assertTrue($historyOrders->every(
            fn (Order $order): bool => $order->status === OrderStatus::Completed && $order->stock_reserved === false
        ));

        $svetmarOrder = Order::query()->where('name', 'HIST|SVETMAR|2026-08-24')->first();
        $this->assertNotNull($svetmarOrder);
        $this->assertSame((int) $existing->id, (int) $svetmarOrder->user_id);
        $this->assertEquals(621.0, (float) $svetmarOrder->amount);
        $this->assertSame(2, $svetmarOrder->orderItems()->count());
        $this->assertSame(1, (int) $svetmarOrder->orderItems()->where('product_id', $matchedProduct->id)->value('amount'));
        $this->assertSame(2, (int) $svetmarOrder->orderItems()->where('product_id', $otherProduct->id)->value('amount'));

        $newCustomer = User::query()->where('company_code', 'ATSTYLE')->first();
        $this->assertNotNull($newCustomer);
        $this->assertTrue($newCustomer->hasRole(User::ROLE_CUSTOMER));

        $again = app(HistoricalSalesOrderImporter::class)->import(path: $path);
        $this->assertSame(0, $again['orders_created']);
        $this->assertSame(2, $again['orders_skipped']);
        $this->assertSame(500, (int) $matchedProduct->fresh()->current_amount);
    }
}
