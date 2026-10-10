<?php

namespace Tests\Unit;

use App\Enums\ActivityLogEvent;
use App\Models\ActivityLog;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductReservation;
use App\Models\StockManualUpdate;
use App\Models\User;
use App\Services\ExternalStockExcelParser;
use App\Services\ExternalStockImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExternalStockImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_reads_preke_kiekis_layout(): void
    {
        $path = $this->writeSpreadsheet([
            ['A12' => 'Prekė', 'G12' => 'Kiekis', 'O12' => 'Vnt. kaina'],
            ['A16' => 'AGNONA101WHITE30', 'C16' => 'Dirbtine oda AGNONA 101-WHITE 30m', 'G16' => 180, 'L16' => 'm', 'O16' => 4.77],
            ['A19' => '/AGNONA/KATALOGAI', 'C19' => 'Katalogas Agnona', 'G19' => 990, 'L19' => 'VNT', 'O19' => 4.47],
        ]);

        $rows = (new ExternalStockExcelParser)->parse($path);

        $this->assertCount(2, $rows);
        $this->assertSame('AGNONA101WHITE30', $rows[0]['code']);
        $this->assertSame(180.0, $rows[0]['quantity']);
        $this->assertSame('m', $rows[0]['unit']);
        $this->assertSame('/AGNONA/KATALOGAI', $rows[1]['code']);
        $this->assertSame('Katalogas Agnona', $rows[1]['name']);
        $this->assertSame(990.0, $rows[1]['quantity']);
        $this->assertSame('stock', $rows[0]['section']);
        $this->assertSame('stock', $rows[1]['section']);
    }

    public function test_parser_splits_samples_warehouse_section(): void
    {
        $path = $this->writeSpreadsheet([
            ['A1' => 'Prekė', 'C1' => 'Pavadinimas', 'G1' => 'Kiekis'],
            ['A2' => 'AGNONA101WHITE30', 'C2' => 'Dirbtinė oda AGNONA 101-WHITE 30m', 'G2' => 180, 'L2' => 'm'],
            ['A3' => 'PAVYZDŽIŲ - Pavyzdžių sandėlis'],
            ['A4' => 'PALOMA106STARFISH30', 'C4' => 'Dirbtinė oda PALOMA 106-STARFISH 30m', 'G4' => 90, 'L4' => 'm'],
        ]);

        $rows = (new ExternalStockExcelParser)->parse($path);

        $this->assertCount(2, $rows);
        $this->assertSame('stock', $rows[0]['section']);
        $this->assertSame('AGNONA101WHITE30', $rows[0]['code']);
        $this->assertSame('reservation', $rows[1]['section']);
        $this->assertSame('PALOMA106STARFISH30', $rows[1]['code']);
        $this->assertSame(90.0, $rows[1]['quantity']);
    }

    public function test_import_matches_collection_color_size_and_logs(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->getKey(),
            'color_code' => '101',
            'color_name' => 'WHITE',
        ]);
        $leather = Product::factory()->create([
            'color_id' => $color->getKey(),
            'name' => '30',
            'product_code' => 'AG101-30',
            'current_amount' => 2,
        ]);
        $catalog = Product::factory()->catalog()->create([
            'name' => 'Katalogas Agnona',
            'product_code' => 'CAT-AG',
            'alternative_code' => '/AGNONA/KATALOGAI',
            'current_amount' => 10,
        ]);

        $path = $this->writeSpreadsheet([
            ['A1' => 'Preke', 'G1' => 'Kiekis'],
            ['A2' => 'AGNONA101WHITE30', 'G2' => 180, 'L2' => 'm'],
            ['A3' => '/AGNONA/KATALOGAI', 'G3' => 990, 'L3' => 'VNT'],
            ['A4' => 'UNKNOWNCODE', 'G4' => 5, 'L4' => 'm'],
        ]);

        $result = (new ExternalStockImporter)->import($path, 'balances.xls', $user->getKey());

        $this->assertSame(1, $result['created']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(0, $result['unchanged']);
        $this->assertSame(0, $result['unmatched']);

        $this->assertSame(6, (int) $leather->fresh()->current_amount);
        $this->assertSame(990, (int) $catalog->fresh()->current_amount);
        $this->assertSame(5, (int) Product::query()->where('product_code', 'UNKNOWNCODE')->value('current_amount'));
        $this->assertTrue(
            Product::query()->where('product_code', 'UNKNOWNCODE')->first()?->isCatalog() ?? false,
        );

        $this->assertSame(3, StockManualUpdate::query()->count());
        $this->assertTrue(
            ActivityLog::query()
                ->where('event_key', ActivityLogEvent::StockImportedFromExcel->value)
                ->where('user_id', $user->getKey())
                ->exists(),
        );
        $this->assertSame(
            0,
            ActivityLog::query()->where('event_key', ActivityLogEvent::ProductUpdated->value)->count(),
        );
    }

    public function test_import_matches_alternative_code_without_converting_pieces(): void
    {
        $product = Product::factory()->create([
            'name' => '20',
            'product_code' => 'XX999-20',
            'alternative_code' => '25229E106STARFISH30',
            'current_amount' => 1,
        ]);

        $path = $this->writeSpreadsheet([
            ['A1' => 'Preke', 'G1' => 'Kiekis'],
            ['A2' => '25229E106STARFISH30', 'G2' => 4, 'L2' => 'VNT'],
        ]);

        (new ExternalStockImporter)->import($path, 'alt.xls');

        $this->assertSame(4, (int) $product->fresh()->current_amount);
    }

    public function test_import_matches_color_code_name_and_size_suffix(): void
    {
        $collection = Collection::factory()->create(['name' => 'Paloma']);
        $color = Color::factory()->create([
            'collection_id' => $collection->getKey(),
            'color_code' => '106',
            'color_name' => 'STARFISH',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->getKey(),
            'name' => '30',
            'product_code' => 'PA106-30',
            'current_amount' => 0,
        ]);

        $path = $this->writeSpreadsheet([
            ['A1' => 'Prekė', 'G1' => 'Kiekis'],
            ['A2' => '25229E106STARFISH30', 'G2' => 30, 'L2' => 'm'],
        ]);

        (new ExternalStockImporter)->import($path, 'paloma.xls');

        $this->assertSame(1, (int) $product->fresh()->current_amount);
    }

    public function test_import_creates_leather_from_title_and_updates_code_and_cost(): void
    {
        $path = $this->writeSpreadsheet([
            ['A1' => 'Prekė', 'G1' => 'Kiekis', 'O1' => 'Vnt. kaina'],
            ['A2' => 'AGNONA101WHITE30', 'C2' => 'Dirbtinė oda  AGNONA 101-WHITE 30m', 'G2' => 180, 'L2' => 'm', 'O2' => 4.77],
            ['A3' => '/AGNONA/KATALOGAI', 'C3' => 'Katalogas Agnona', 'G3' => 990, 'L3' => 'VNT', 'O3' => 4.47],
            ['A4' => 'Dirbtine oda broken', 'C4' => 'Dirbtinė oda broken row', 'G4' => 10, 'L4' => 'm'],
        ]);

        $result = (new ExternalStockImporter)->import($path, 'create.xls');

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['unmatched']);
        $this->assertContains('Dirbtine oda broken', $result['unmatched_codes']);

        $leather = Product::query()->where('product_code', 'AG101-30')->first();
        $this->assertNotNull($leather);
        $this->assertFalse($leather->isCatalog());
        $this->assertSame('30', (string) $leather->name);
        $this->assertSame('AGNONA101WHITE30', $leather->alternative_code);
        $this->assertSame(6, (int) $leather->current_amount);
        $this->assertEquals(4.77, (float) $leather->cost);
        $this->assertEquals(4.77, (float) $leather->color?->collection?->cost);
        $this->assertSame('Agnona', $leather->color?->collection?->name);
        $this->assertSame('101', $leather->color?->color_code);
        $this->assertSame('WHITE', $leather->color?->color_name);

        $catalog = Product::query()->where('product_code', '/AGNONA/KATALOGAI')->first();
        $this->assertNotNull($catalog);
        $this->assertTrue($catalog->isCatalog());
        $this->assertSame('-', (string) $catalog->name);
        $this->assertSame('Katalogas Agnona', $catalog->color?->color_name);
        $this->assertSame('Agnona', $catalog->color?->collection?->name);
        $this->assertSame(990, (int) $catalog->current_amount);
        $this->assertEquals(4.47, (float) $catalog->cost);
    }

    public function test_import_updates_existing_product_code_from_title_size(): void
    {
        $collection = Collection::factory()->create(['name' => 'Agnona', 'cost' => 1]);
        $color = Color::factory()->create([
            'collection_id' => $collection->getKey(),
            'color_code' => '101',
            'color_name' => 'WHITE',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->getKey(),
            'name' => '20',
            'product_code' => 'OLD-AG-20',
            'current_amount' => 1,
        ]);

        $path = $this->writeSpreadsheet([
            ['A1' => 'Prekė', 'G1' => 'Kiekis', 'O1' => 'Vnt. kaina'],
            ['A2' => 'AGNONA101WHITE30', 'C2' => 'Dirbtinė oda  AGNONA 101-WHITE 30m', 'G2' => 90, 'L2' => 'm', 'O2' => 5.5],
        ]);

        (new ExternalStockImporter)->import($path, 'codes.xls');

        $this->assertSame('OLD-AG-20', $product->fresh()->product_code);
        $this->assertSame(1, (int) $product->fresh()->current_amount);

        $created = Product::query()->where('product_code', 'AG101-30')->first();
        $this->assertNotNull($created);
        $this->assertSame('30', (string) $created->name);
        $this->assertSame(3, (int) $created->current_amount);
        $this->assertEquals(5.5, (float) $created->cost);
        $this->assertEquals(5.5, (float) $collection->fresh()->cost);
    }

    public function test_import_rewrites_product_code_when_size_already_matches(): void
    {
        $collection = Collection::factory()->create(['name' => 'Agnona', 'cost' => 1]);
        $color = Color::factory()->create([
            'collection_id' => $collection->getKey(),
            'color_code' => '132',
            'color_name' => 'BLUE',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->getKey(),
            'name' => '26',
            'product_code' => 'WRONG-CODE',
            'current_amount' => 0,
        ]);

        $path = $this->writeSpreadsheet([
            ['A1' => 'Prekė', 'G1' => 'Kiekis'],
            ['A2' => 'AGNONA132BLUE26', 'C2' => 'Dirbtinė oda  AGNONA 132-BLUE 26m', 'G2' => 26, 'L2' => 'm'],
        ]);

        (new ExternalStockImporter)->import($path, 'rewrite.xls');

        $product->refresh();
        $this->assertSame('AG132-26', $product->product_code);
        $this->assertSame('AGNONA132BLUE26', $product->alternative_code);
        $this->assertSame('26', (string) $product->name);
        $this->assertSame(1, (int) $product->current_amount);
    }

    public function test_import_creates_separate_catalog_for_each_collection(): void
    {
        $path = $this->writeSpreadsheet([
            ['A1' => 'Prekė', 'C1' => 'Pavadinimas', 'G1' => 'Kiekis', 'O1' => 'Vnt. kaina'],
            ['A2' => '/AGNONA/KATALOGAI', 'C2' => 'Katalogas Agnona', 'G2' => 990, 'L2' => 'VNT', 'O2' => 4.47],
            ['A3' => '/ARGANO/KATALOGAI', 'C3' => 'Katalogas Argano', 'G3' => 850, 'L3' => 'VNT', 'O3' => 3.81],
            ['A4' => '/BORGA/KATALOGAI', 'C4' => 'Katalogas Borga', 'G4' => 900, 'L4' => 'VNT', 'O4' => 3.58],
            ['A5' => '/PALOMA/KATALOGAI', 'C5' => 'Katalogas Paloma', 'G5' => 1160, 'L5' => 'VNT', 'O5' => 4.13],
            ['A6' => '/SARAMO/KATALOGAI', 'C6' => 'Katalogas Saramo', 'G6' => 1170, 'L6' => 'VNT', 'O6' => 3.05],
        ]);

        $result = (new ExternalStockImporter)->import($path, 'catalogs.xls');

        $this->assertSame(5, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['unmatched']);
        $this->assertSame(5, Product::query()->catalog()->count());

        $agnona = Product::query()->where('product_code', '/AGNONA/KATALOGAI')->first();
        $argano = Product::query()->where('product_code', '/ARGANO/KATALOGAI')->first();
        $this->assertNotNull($agnona);
        $this->assertNotNull($argano);
        $this->assertNotSame($agnona->getKey(), $argano->getKey());
        $this->assertTrue($agnona->isCatalog());
        $this->assertTrue($argano->isCatalog());
        $this->assertSame('-', (string) $agnona->name);
        $this->assertSame('Katalogas Agnona', $agnona->color?->color_name);
        $this->assertSame('Agnona', $agnona->color?->collection?->name);
        $this->assertSame('Katalogas Argano', $argano->color?->color_name);
        $this->assertSame('Argano', $argano->color?->collection?->name);
        $this->assertSame(990, (int) $agnona->current_amount);
        $this->assertSame(850, (int) $argano->current_amount);
        $this->assertEquals(4.47, (float) $agnona->cost);
        $this->assertEquals(3.81, (float) $argano->cost);
    }

    public function test_import_sets_catalog_name_from_katalogas_column(): void
    {
        $catalog = Product::factory()->catalog()->create([
            'name' => '/AGNONA/KATALOGAI',
            'product_code' => '/AGNONA/KATALOGAI',
            'alternative_code' => '/AGNONA/KATALOGAI',
            'current_amount' => 10,
        ]);

        $path = $this->writeSpreadsheet([
            ['A1' => 'Prekė', 'G1' => 'Kiekis'],
            ['A2' => '/AGNONA/KATALOGAI', 'D2' => 'Katalogas Agnona', 'G2' => 990, 'L2' => 'VNT'],
            ['A3' => '/ARGANO/KATALOGAI', 'D3' => 'Katalogas Argano', 'G3' => 850, 'L3' => 'VNT'],
        ]);

        (new ExternalStockImporter)->import($path, 'catalog-names.xls');

        $catalog->refresh()->load('color.collection');
        $this->assertSame('-', (string) $catalog->name);
        $this->assertSame('Katalogas Agnona', $catalog->color?->color_name);
        $this->assertSame('Agnona', $catalog->color?->collection?->name);
        $this->assertSame(
            'Katalogas Argano',
            Product::query()->where('product_code', '/ARGANO/KATALOGAI')->first()?->color?->color_name,
        );
    }

    public function test_import_sends_samples_warehouse_rows_to_reservations(): void
    {
        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $stockColor = Color::factory()->create([
            'collection_id' => $collection->getKey(),
            'color_code' => '101',
            'color_name' => 'WHITE',
        ]);
        $stock = Product::factory()->create([
            'color_id' => $stockColor->getKey(),
            'name' => '30',
            'product_code' => 'AG101-30',
            'current_amount' => 2,
        ]);

        $sampleCollection = Collection::factory()->create(['name' => 'Paloma']);
        $sampleColor = Color::factory()->create([
            'collection_id' => $sampleCollection->getKey(),
            'color_code' => '106',
            'color_name' => 'STARFISH',
        ]);
        $sample = Product::factory()->create([
            'color_id' => $sampleColor->getKey(),
            'name' => '30',
            'product_code' => 'PA106-30',
            'current_amount' => 8,
        ]);

        $path = $this->writeSpreadsheet([
            ['A1' => 'Prekė', 'C1' => 'Pavadinimas', 'G1' => 'Kiekis'],
            ['A2' => 'AGNONA101WHITE30', 'C2' => 'Dirbtinė oda AGNONA 101-WHITE 30m', 'G2' => 180, 'L2' => 'm'],
            ['A3' => 'PAVYZDŽIŲ - Pavyzdžių sandėlis'],
            ['A4' => 'PALOMA106STARFISH30', 'C4' => 'Dirbtinė oda PALOMA 106-STARFISH 30m', 'G4' => 90, 'L4' => 'm'],
        ]);

        $result = (new ExternalStockImporter)->import($path, 'samples.xls');

        $this->assertSame(6, (int) $stock->fresh()->current_amount);
        $this->assertSame(8, (int) $sample->fresh()->current_amount);
        $this->assertSame(1, $result['reservations_updated']);
        $this->assertSame(3, (int) ProductReservation::query()->where('product_id', $sample->id)->value('quantity'));
        $this->assertNull(ProductReservation::query()->where('product_id', $stock->id)->value('quantity'));
    }

    /**
     * @param  list<array<string, mixed>>  $cellsByRow
     */
    private function writeSpreadsheet(array $cellsByRow): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($cellsByRow as $cells) {
            foreach ($cells as $coordinate => $value) {
                $sheet->setCellValue($coordinate, $value);
            }
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stock-import-'.uniqid('', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->assertFileExists($path);

        return $path;
    }
}
