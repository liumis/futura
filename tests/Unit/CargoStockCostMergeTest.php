<?php

namespace Tests\Unit;

use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\ImportTax;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\WarehouseImport;
use App\Services\CargoCostCalculator;
use App\Services\CargoReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CargoStockCostMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_and_other_costs_are_divided_by_goods_value(): void
    {
        $expensive = Product::factory()->create([
            'name' => '30',
            'current_amount' => 0,
            'cost' => 10,
        ]);
        $cheap = Product::factory()->create([
            'name' => '20',
            'current_amount' => 0,
            'cost' => 5,
        ]);

        $cargo = $this->makeCargo(
            shipping: 70,
            additional: 0,
            items: [
                ['product' => $expensive, 'amount' => 2, 'self_cost' => 10],
                ['product' => $cheap, 'amount' => 1, 'self_cost' => 5],
            ],
        );

        $expensiveItem = $cargo->cargoItems->firstWhere('product_id', $expensive->getKey());
        $cheapItem = $cargo->cargoItems->firstWhere('product_id', $cheap->getKey());

        $this->assertSame(600.0, $expensiveItem->goodsValue());
        $this->assertSame(100.0, $cheapItem->goodsValue());
        $this->assertSame(70.0, CargoCostCalculator::overheadTotal($cargo));
        $this->assertSame(60.0, CargoCostCalculator::itemOverheadTotal($cargo, $expensiveItem));
        $this->assertSame(10.0, CargoCostCalculator::itemOverheadTotal($cargo, $cheapItem));
        $this->assertSame(11.0, CargoCostCalculator::landedStockUnitCost($cargo, $expensiveItem));
        $this->assertSame(5.5, CargoCostCalculator::landedStockUnitCost($cargo, $cheapItem));
    }

    public function test_import_tax_is_included_in_the_overhead_pool(): void
    {
        $product = Product::factory()->create([
            'name' => '30',
            'current_amount' => 0,
            'cost' => 10,
        ]);
        $tax = ImportTax::query()->create([
            'name' => 'Import 10%',
            'rate' => 10,
        ]);
        $cargo = $this->makeCargo(
            shipping: 70,
            additional: 30,
            importTaxId: $tax->getKey(),
            items: [
                ['product' => $product, 'amount' => 2, 'self_cost' => 10],
            ],
        );

        $item = $cargo->cargoItems->first();
        $goods = 600.0;
        $taxAmount = round(($goods + 70 + 30) * 0.10, 2);
        $overhead = 70 + 30 + $taxAmount;

        $this->assertSame($taxAmount, CargoCostCalculator::importTaxesFor($cargo));
        $this->assertSame($overhead, CargoCostCalculator::overheadTotal($cargo));
        $this->assertSame($overhead, CargoCostCalculator::itemOverheadTotal($cargo, $item));
    }

    public function test_new_arrival_increases_existing_stock_cost(): void
    {
        $product = Product::factory()->create([
            'name' => '30',
            'current_amount' => 10,
            'cost' => 10,
        ]);
        $cargo = $this->makeCargo(
            shipping: 0,
            additional: 0,
            items: [
                ['product' => $product, 'amount' => 10, 'self_cost' => 20],
            ],
        );

        CargoResource::importCargoItemsToProductStock($cargo);

        $product->refresh();

        $this->assertSame(20, $product->current_amount);
        $this->assertSame(15.0, (float) $product->cost);
    }

    public function test_cheaper_arrival_decreases_existing_stock_cost(): void
    {
        $product = Product::factory()->create([
            'name' => '30',
            'current_amount' => 10,
            'cost' => 20,
        ]);
        $cargo = $this->makeCargo(
            shipping: 0,
            additional: 0,
            items: [
                ['product' => $product, 'amount' => 10, 'self_cost' => 10],
            ],
        );

        CargoResource::importCargoItemsToProductStock($cargo);

        $this->assertSame(15.0, (float) $product->fresh()->cost);
    }

    public function test_empty_stock_takes_landed_arrival_cost(): void
    {
        $product = Product::factory()->create([
            'name' => '30',
            'current_amount' => 0,
            'cost' => 99,
        ]);
        $cargo = $this->makeCargo(
            shipping: 60,
            additional: 0,
            items: [
                ['product' => $product, 'amount' => 2, 'self_cost' => 10],
            ],
        );

        CargoResource::importCargoItemsToProductStock($cargo);

        $product->refresh();

        $this->assertSame(2, $product->current_amount);
        $this->assertSame(11.0, (float) $product->cost);
    }

    public function test_catalog_stock_uses_per_piece_weighted_average(): void
    {
        $product = Product::factory()->catalog()->create([
            'current_amount' => 4,
            'cost' => 10,
        ]);
        $cargo = $this->makeCargo(
            shipping: 20,
            additional: 0,
            items: [
                ['product' => $product, 'amount' => 4, 'self_cost' => 20],
            ],
        );

        CargoResource::importCargoItemsToProductStock($cargo);

        $product->refresh();

        $this->assertSame(8, $product->current_amount);
        $this->assertSame(17.5, (float) $product->cost);
    }

    public function test_receive_and_import_persists_merged_cost_and_received_lines(): void
    {
        $product = Product::factory()->create([
            'name' => '30',
            'current_amount' => 10,
            'cost' => 10,
        ]);
        $supplier = Supplier::query()->create(['name' => 'Test mill']);
        $product->color->collection->update(['supplier_id' => $supplier->getKey()]);

        $cargo = $this->makeCargo(
            shipping: 0,
            additional: 0,
            status: CargoStatus::Shipped,
            items: [
                ['product' => $product, 'amount' => 10, 'self_cost' => 20],
            ],
        );
        $cargo->update(['supplier_id' => $supplier->getKey()]);

        CargoReceiver::receiveAndImport($cargo);

        $product->refresh();
        $import = WarehouseImport::query()->where('cargo_id', $cargo->getKey())->first();

        $this->assertSame(CargoStatus::Received, $cargo->fresh()->status);
        // Stock is applied later from Warehouse imports — receive only creates the pending row.
        $this->assertSame(10, $product->current_amount);
        $this->assertSame(10.0, (float) $product->cost);
        $this->assertNotNull($import);
        $this->assertSame(600.0, (float) $import->base_cost);
        $this->assertSame(0.0, (float) $import->overhead_cost);
        $this->assertSame(600.0, (float) $import->cost);
    }

    /**
     * @param  list<array{product: Product, amount: int, self_cost: float}>  $items
     */
    private function makeCargo(
        float $shipping,
        float $additional,
        array $items,
        ?int $importTaxId = null,
        CargoStatus $status = CargoStatus::Shipped,
    ): Cargo {
        $cargo = Cargo::query()->create([
            'status' => $status,
            'date_shipped' => '2026-07-01',
            'estimated_arrival' => '2026-08-01',
            'shipping_cost' => $shipping,
            'additional_cost' => $additional,
            'import_tax_id' => $importTaxId,
        ]);

        foreach ($items as $row) {
            CargoItem::query()->create([
                'cargo_id' => $cargo->getKey(),
                'product_id' => $row['product']->getKey(),
                'amount' => $row['amount'],
                'self_cost' => $row['self_cost'],
            ]);
        }

        return $cargo->fresh(['cargoItems.product.productType', 'importTax']);
    }
}
