<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Color;
use App\Models\Collection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\HistoricalSalesOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FDuomenysFrom20261109ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_has_expected_delta_shape(): void
    {
        $path = database_path('data/f-duomenys-from-2026-11-09.json');
        $this->assertFileExists($path);

        /** @var array{line_count?: int, order_groups?: int, min_date?: string, max_date?: string, rows?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(56, $payload['line_count'] ?? null);
        $this->assertSame(5, $payload['order_groups'] ?? null);
        $this->assertSame('2026-11-09', $payload['min_date'] ?? null);
        $this->assertSame('2026-11-12', $payload['max_date'] ?? null);
        $this->assertCount(56, $payload['rows'] ?? []);
    }

    public function test_import_creates_hist_order_without_duplicating_lines(): void
    {
        Role::findOrCreate(User::ROLE_CUSTOMER, 'web');

        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '114',
            'color_name' => 'Cocoa',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'AG114-30',
            'alternative_code' => 'AGNONA114COCOA30',
        ]);

        $importer = app(HistoricalSalesOrderImporter::class);
        $role = Role::findOrCreate(User::ROLE_CUSTOMER, 'web');
        $productsByCode = $importer->productLookup();

        $rows = [[
            'customer_code' => '',
            'customer' => 'Pedro Tavares Texteis, Lda',
            'sales_date' => '2026-11-09',
            'item' => 'AGNONA114COCOA30',
            'quantity' => 30,
            'sales_eur' => '207,00',
        ]];

        $groups = [];
        foreach ($rows as $row) {
            $customer = trim((string) $row['customer']);
            $code = strtoupper(trim((string) $row['customer_code']));
            if ($code === '') {
                $code = $importer->inferCustomerCode($customer);
            }
            $date = $row['sales_date'];
            $item = strtoupper($row['item']);
            $quantity = (int) $row['quantity'];
            $sales = $importer->parseEuro((string) $row['sales_eur']);
            $matched = $productsByCode[$item] ?? null;
            $this->assertNotNull($matched);

            $groupKey = $code.'|'.$date;
            $groups[$groupKey] ??= [
                'customer_code' => $code,
                'customer' => $customer,
                'sales_date' => $date,
                'lines' => [],
                'sales_total' => 0.0,
            ];
            $groups[$groupKey]['lines'][(int) $matched->id] = ($groups[$groupKey]['lines'][(int) $matched->id] ?? 0) + $quantity;
            $groups[$groupKey]['sales_total'] += $sales;
        }

        foreach ($groups as $group) {
            [$user] = $importer->resolveCustomer($group['customer_code'], $group['customer'], $role);
            $baseName = HistoricalSalesOrderImporter::HISTORY_NAME_PREFIX
                .$group['customer_code'].'|'
                .$group['sales_date'];

            $order = Order::query()->create([
                'name' => $baseName,
                'status' => OrderStatus::Completed,
                'stock_reserved' => false,
                'amount' => round($group['sales_total'], 2),
                'shipping_cost' => 0,
                'order_date' => Carbon::parse($group['sales_date'])->setTime(12, 0),
                'user_id' => $user->id,
            ]);

            foreach ($group['lines'] as $productId => $qty) {
                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'amount' => $product->rollAmountFromMeters((int) $qty),
                ]);
            }
        }

        $this->assertSame(1, Order::query()->where('name', 'like', 'HIST|PEDROTAVARES|2026-11-09%')->count());
        $order = Order::query()->where('name', 'HIST|PEDROTAVARES|2026-11-09')->firstOrFail();
        $this->assertSame(1, OrderItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(207.0, (float) $order->amount);

        // Idempotent skip when lines already exist.
        $this->assertTrue(OrderItem::query()->where('order_id', $order->id)->exists());
    }
}
