<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\CatalogPricing;
use App\Models\Collection;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CollectionPricing;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CatalogPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_math_matches_screenshot_example(): void
    {
        $customs = CollectionPricing::customs(2.3);
        $cost = CollectionPricing::costPrice(2.3, 0.10, 0.30);
        $price = CollectionPricing::sellPrice($cost, 50);

        $this->assertSame(0.18, $customs);
        $this->assertSame(2.88, $cost);
        $this->assertSame(4.32, $price);
    }

    public function test_pricing_page_saves_breakdown_and_syncs_cost_price(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create([
            'name' => 'BARI',
            'price' => 0,
            'cost' => 0,
            'port_price' => null,
            'agent_fee' => null,
            'other_cost' => null,
            'markup_percent' => null,
        ]);

        Livewire::test(CatalogPricing::class)
            ->assertOk()
            ->assertSee('Pricing')
            ->assertSee('Customs')
            ->assertSee('Base price')
            ->assertSee('BARI')
            ->call('saveField', $collection->id, 'port_price', '2,3')
            ->call('saveField', $collection->id, 'other_cost', '0,30')
            ->call('saveField', $collection->id, 'markup_percent', '50');

        $collection->refresh();

        $this->assertEquals(2.3, (float) $collection->port_price);
        $this->assertEquals(0.1, (float) $collection->agent_fee);
        $this->assertEquals(0.3, (float) $collection->other_cost);
        $this->assertEquals(50, (float) $collection->markup_percent);
        $this->assertEquals(2.88, (float) $collection->cost);
        $this->assertEquals(4.32, (float) $collection->price);
    }

    public function test_agent_defaults_to_zero_point_one_when_port_price_is_set(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create([
            'name' => 'FERRARA',
            'price' => 0,
            'cost' => 0,
            'port_price' => null,
            'agent_fee' => null,
            'other_cost' => null,
            'markup_percent' => null,
        ]);

        Livewire::test(CatalogPricing::class)
            ->call('saveField', $collection->id, 'port_price', '10 €');

        $collection->refresh();

        $this->assertEquals(0.1, (float) $collection->agent_fee);
        $this->assertEquals(0.8, CollectionPricing::customs(10.0));
        $this->assertEquals(10.9, (float) $collection->cost); // 10 + 0.8 + 0.1
    }

    public function test_agent_can_be_cleared_to_null(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create([
            'name' => 'CLEAR AGENT',
            'price' => 0,
            'cost' => 0,
            'port_price' => 4.3,
            'agent_fee' => 0.1,
            'other_cost' => 0.3,
            'markup_percent' => 33,
        ]);

        Livewire::test(CatalogPricing::class)
            ->call('saveField', $collection->id, 'agent_fee', '');

        $collection->refresh();

        $this->assertNull($collection->agent_fee);
        $this->assertEquals(4.94, (float) $collection->cost); // 4.3 + 0.34 + 0 + 0.3
    }

    public function test_pricing_page_can_add_collection(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::query()->create(['name' => 'Pricing Supplier']);
        $warehouse = Warehouse::query()->create([
            'name' => 'Pricing Warehouse',
            'is_default' => true,
        ]);

        Livewire::test(CatalogPricing::class)
            ->callAction('add', [
                'name' => 'PALERMO',
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'port_price' => 3.5,
                'agent_fee' => 0.10,
                'other_cost' => 0.30,
                'markup_percent' => 40,
            ]);

        $collection = Collection::query()->where('name', 'PALERMO')->first();
        $this->assertNotNull($collection);
        $this->assertEquals(4.18, (float) $collection->cost); // 3.5 + 0.28 + 0.1 + 0.3
        $this->assertEquals(5.85, (float) $collection->price); // 4.18 * 1.4
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-catalog-pricing@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
