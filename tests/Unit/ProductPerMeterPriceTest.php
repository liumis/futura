<?php

namespace Tests\Unit;

use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPerMeterPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_leather_price_is_per_meter_times_size(): void
    {
        $product = Product::factory()->create([
            'name' => '30',
        ]);
        $product->color->collection->update(['price' => 200]);
        $product->unsetRelation('color');

        $this->assertSame(30.0, $product->sizeMeters());
        $this->assertSame(200.0, $product->collectionUnitPrice());
        $this->assertSame(6000.0, $product->lineAmount());
        $this->assertSame(12000.0, $product->lineAmount(null, 2));
    }

    public function test_catalog_has_no_collection_price(): void
    {
        $product = Product::factory()->catalog()->create([
            'name' => '30',
        ]);

        $this->assertNull($product->sizeMeters());
        $this->assertSame(0.0, $product->collectionUnitPrice());
        $this->assertSame(200.0, $product->lineAmount(200, 1));
        $this->assertSame(400.0, $product->lineAmount(200, 2));
    }

    public function test_warehouse_order_full_cost_multiplies_price_by_meters(): void
    {
        $product = Product::factory()->create([
            'name' => '30',
        ]);

        $total = CargoResource::calculateFullCostWithoutShipping(
            amounts: [$product->getKey() => 8],
            costs: [$product->getKey() => 200],
        );

        $this->assertSame(48000.0, $total);
    }
}
