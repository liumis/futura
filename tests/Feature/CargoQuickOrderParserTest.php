<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\CargoQuickOrderParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CargoQuickOrderParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_collection_header_with_color_code_and_name_lines(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Header Supplier']);
        $collection = Collection::factory()->create([
            'name' => 'Agnona',
            'supplier_id' => $supplier->id,
        ]);
        $black = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '101',
            'color_name' => 'Black',
        ]);
        $arctic = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '102',
            'color_name' => 'Arctic',
        ]);
        $blackProduct = Product::factory()->create([
            'color_id' => $black->id,
            'name' => '30',
            'product_code' => 'AG101BLACK-30',
            'current_amount' => 12,
        ]);
        $arcticProduct = Product::factory()->create([
            'color_id' => $arctic->id,
            'name' => '30',
            'product_code' => 'AG102ARCTIC-30',
            'current_amount' => 8,
        ]);

        $lines = app(CargoQuickOrderParser::class)->parse(
            "agnona\n101 - 10\nArctic - 20\n",
            $supplier->id,
        );

        $this->assertCount(2, $lines);

        $byId = collect($lines)->keyBy('product_id');
        $this->assertTrue($byId[$blackProduct->id]['matched']);
        $this->assertSame(10, $byId[$blackProduct->id]['amount']);
        $this->assertTrue($byId[$arcticProduct->id]['matched']);
        $this->assertSame(20, $byId[$arcticProduct->id]['amount']);
    }

    public function test_parses_collection_color_code_and_quantity(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Color Code Supplier']);
        $collection = Collection::factory()->create([
            'name' => 'Agnona',
            'supplier_id' => $supplier->id,
        ]);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '101',
            'color_name' => 'Black',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'AG101BLACK-30',
            'alternative_code' => 'AGNONA101BLACK30',
            'current_amount' => 40,
        ]);

        $lines = app(CargoQuickOrderParser::class)->parse(
            "agnona 101 - 10\n",
            $supplier->id,
        );

        $this->assertCount(1, $lines);
        $this->assertTrue($lines[0]['matched']);
        $this->assertSame($product->id, $lines[0]['product_id']);
        $this->assertSame(10, $lines[0]['amount']);
        $this->assertStringContainsString('101', $lines[0]['label']);
    }

    public function test_parses_excel_style_code_with_quantity(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Test Supplier']);
        $collection = Collection::factory()->create([
            'name' => 'Agnona',
            'supplier_id' => $supplier->id,
        ]);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '102',
            'color_name' => 'Arctic',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'AG102ARCTIC-30',
            'alternative_code' => 'AGNONA102ARCTIC30',
            'current_amount' => 570,
        ]);

        $lines = app(CargoQuickOrderParser::class)->parse(
            "AGNONA102ARCTIC30 — 3 vnt\n",
            $supplier->id,
        );

        $this->assertCount(1, $lines);
        $this->assertTrue($lines[0]['matched']);
        $this->assertSame($product->id, $lines[0]['product_id']);
        $this->assertSame(3, $lines[0]['amount']);
        $this->assertSame(570, $lines[0]['stock']);
        $this->assertSame(30.0, $lines[0]['size_meters']);
    }

    public function test_parses_internal_code_and_merges_duplicates(): void
    {
        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '102',
            'color_name' => 'Arctic',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'AG102ARCTIC-30',
            'alternative_code' => 'AGNONA102ARCTIC30',
        ]);

        $lines = app(CargoQuickOrderParser::class)->parse(
            "AG102ARCTIC-30 2\nAGNONA102ARCTIC30 — 1 vnt",
        );

        $this->assertCount(1, $lines);
        $this->assertSame($product->id, $lines[0]['product_id']);
        $this->assertSame(3, $lines[0]['amount']);
    }

    public function test_converts_meters_to_rolls_when_size_known(): void
    {
        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '102',
            'color_name' => 'Arctic',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'AG102ARCTIC-30',
            'alternative_code' => 'AGNONA102ARCTIC30',
        ]);

        $lines = app(CargoQuickOrderParser::class)->parse(
            'AGNONA102ARCTIC30 — 90 m',
        );

        $this->assertCount(1, $lines);
        $this->assertSame($product->id, $lines[0]['product_id']);
        $this->assertSame(3, $lines[0]['amount']);
    }

    public function test_summary_lists_matched_lines(): void
    {
        $parser = app(CargoQuickOrderParser::class);
        $summary = $parser->summary([
            [
                'matched' => true,
                'code' => 'AGNONA102ARCTIC30',
                'amount' => 3,
                'unit' => 'rolls',
            ],
            [
                'matched' => false,
                'code' => 'UNKNOWN',
                'amount' => 1,
                'unit' => 'vnt',
            ],
        ]);

        $this->assertStringContainsString('Užsakymas:', $summary);
        $this->assertStringContainsString('AGNONA102ARCTIC30 — 3 rolls', $summary);
        $this->assertStringNotContainsString('UNKNOWN', $summary);
    }

    public function test_does_not_match_catalog_products(): void
    {
        $catalog = Product::factory()->catalog()->create([
            'name' => 'Sample Book',
            'product_code' => 'CATALOG-SAMPLE-BOOK',
            'alternative_code' => 'CATALOGSAMPLEBOOK',
        ]);

        $lines = app(CargoQuickOrderParser::class)->parse(
            'CATALOGSAMPLEBOOK — 2 vnt',
        );

        $this->assertTrue(
            collect($lines)->every(fn (array $line): bool => (int) ($line['product_id'] ?? 0) !== $catalog->id),
        );
    }

    public function test_supplier_product_ids_exclude_catalog(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Leather Only Supplier']);
        $collection = Collection::factory()->create([
            'name' => 'Agnona',
            'supplier_id' => $supplier->id,
        ]);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '102',
            'color_name' => 'Arctic',
        ]);
        $leather = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'AG102ARCTIC-30',
        ]);
        $catalog = Product::factory()->catalog()->create([
            'name' => 'Catalog item',
            'product_code' => 'CAT-ITEM-1',
        ]);

        $ids = CargoResource::productIdsForSupplier($supplier->id);

        $this->assertContains($leather->id, $ids);
        $this->assertNotContains($catalog->id, $ids);
    }
}
