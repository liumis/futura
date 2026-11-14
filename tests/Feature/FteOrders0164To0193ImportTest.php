<?php

namespace Tests\Feature;

use App\Models\Color;
use App\Models\Collection;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\FuturaSalesInvoicePdfImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FteOrders0164To0193ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_import_creates_orders_and_invoices_without_duplicating(): void
    {
        Role::findOrCreate(User::ROLE_CUSTOMER, 'web');
        User::factory()->create(['email' => 'admin-fte-snap@example.com']);

        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '121',
            'color_name' => 'Black',
        ]);
        Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'AG121-30',
            'alternative_code' => 'AGNONA121BLACK30',
        ]);

        $rows = [[
            'invoice_number' => 'FTE0165',
            'invoice_date' => '2026-10-09',
            'customer_code' => 'VINILAS',
            'customer_name' => 'Vinilas, UAB',
            'provider_company_vat' => 'LT100009844217',
            'provider_company_id' => '304140910',
            'sum_without_vat' => 192.0,
            'vat' => 40.32,
            'sum_inc_vat' => 232.32,
            'lines' => [
                ['code' => 'AGNONA121BLACK30', 'meters' => 30],
            ],
        ]];

        $importer = app(FuturaSalesInvoicePdfImporter::class);
        $first = $importer->importSnapshotRows($rows, linkBank: false);
        $second = $importer->importSnapshotRows($rows, linkBank: false);

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $first['orders_created']);
        $this->assertSame(1, $first['items_created']);
        $this->assertSame(1, $second['skipped_existing']);
        $this->assertSame(0, $second['created']);

        $this->assertSame(1, Order::query()->where('name', 'HIST|VINILAS|2026-10-09|FTE0165')->count());
        $this->assertSame(1, Invoice::query()->where('invoice_number', 'FTE0165')->count());
        $this->assertDatabaseHas('orders', [
            'name' => 'HIST|VINILAS|2026-10-09|FTE0165',
            'amount' => 192,
        ]);
    }

    public function test_migration_snapshot_file_is_present_and_well_formed(): void
    {
        $path = database_path('data/fte-orders-0164-0193.json');
        $this->assertTrue(File::exists($path), 'Snapshot JSON missing for prod migration.');

        $payload = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload['rows'] ?? null);
        $this->assertCount(30, $payload['rows']);
        $this->assertSame('FTE0164', $payload['rows'][0]['invoice_number']);
        $this->assertSame('FTE0193', $payload['rows'][29]['invoice_number']);
    }
}
