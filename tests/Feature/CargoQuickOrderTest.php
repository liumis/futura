<?php

namespace Tests\Feature;

use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Cargos\Pages\CreateCargo;
use App\Models\Cargo;
use App\Models\CargoItem;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CargoQuickOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_cargo_has_quick_order_action(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateCargo::class)
            ->assertOk()
            ->assertActionExists('quickOrder')
            ->assertActionExists('autoOrder')
            ->assertActionExists('expandOrderCart');
    }

    public function test_quick_order_applies_matched_amounts_to_form(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::query()->create(['name' => 'Quick Order Supplier']);
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

        $component = Livewire::test(CreateCargo::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
            ])
            ->callAction('quickOrder', data: [
                'raw_text' => 'AGNONA102ARCTIC30 — 3 vnt',
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'matched' => true,
                        'code' => 'AGNONA102ARCTIC30',
                        'label' => 'Agnona',
                        'amount' => 3,
                        'unit' => 'rolls',
                        'stock' => 570,
                        'size_meters' => 30.0,
                        'detail' => '3 vnt × 30 m = 90 m · stock 570',
                    ],
                ],
            ]);

        $amounts = $component->get('data.cargo_amounts');
        $this->assertSame(3, (int) ($amounts[(string) $product->id] ?? 0));
    }

    public function test_quick_order_draft_is_restored_when_reopened(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::query()->create(['name' => 'Draft Supplier']);
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
        ]);

        $component = Livewire::test(CreateCargo::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
            ])
            ->callAction('quickOrder', data: [
                'raw_text' => "agnona\n102 - 3",
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'matched' => true,
                        'code' => 'AGNONA102ARCTIC30',
                        'label' => 'Agnona · 102 Arctic · 30 m',
                        'amount' => 3,
                        'unit' => 'rolls',
                        'stock' => 0,
                        'size_meters' => 30.0,
                        'detail' => '3 vnt × 30 m = 90 m · stock 0',
                    ],
                ],
            ]);

        $draft = $component->get('quickOrderDraft');
        $this->assertSame("agnona\n102 - 3", $draft['raw_text']);
        $this->assertCount(1, $draft['lines']);
        $this->assertSame(3, (int) ($component->get('data.cargo_amounts')[(string) $product->id] ?? 0));
        $this->assertTrue((bool) $component->get('orderCartOpen'));
        $this->assertCount(1, $component->get('orderCartItems'));

        $component
            ->call('updateOrderCartAmount', $product->id, 5)
            ->assertSet('data.cargo_amounts.'.(string) $product->id, 5)
            ->call('removeOrderCartItem', $product->id)
            ->assertSet('data.cargo_amounts.'.(string) $product->id, 0);
    }

    public function test_product_amount_input_updates_order_cart(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::query()->create(['name' => 'Sync Supplier']);
        $collection = Collection::factory()->create([
            'name' => 'Agnona',
            'supplier_id' => $supplier->id,
        ]);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '161',
            'color_name' => 'Orange',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'AG161-30',
        ]);

        $component = Livewire::test(CreateCargo::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'cargo_amounts' => [
                    (string) $product->id => 2,
                ],
            ])
            ->call('onCargoAmountInputUpdated', $product->id, 2);

        $this->assertSame(2, (int) ($component->get('data.cargo_amounts')[(string) $product->id] ?? 0));
        $this->assertCount(1, $component->get('orderCartItems'));
        $this->assertSame(2, $component->get('orderCartItems')[0]['amount']);

        $component
            ->call('updateOrderCartAmount', $product->id, 4)
            ->assertSet('data.cargo_amounts.'.(string) $product->id, 4)
            ->assertDispatched('cargo-amount-synced')
            ->call('updateColorRecommendedStock', $color->id, 900);

        $this->assertSame(900, (int) $color->fresh()->recommended_stock);
    }

    public function test_auto_order_sets_default_amounts_for_low_stock_products(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::query()->create(['name' => 'Auto Order Supplier']);
        $collection = Collection::factory()->create([
            'name' => 'Agnona',
            'supplier_id' => $supplier->id,
        ]);

        $lowColor = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '101',
            'color_name' => 'White',
            'recommended_stock' => 200,
            'default_order' => 15,
        ]);
        $okColor = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '102',
            'color_name' => 'Arctic',
            'recommended_stock' => 200,
            'default_order' => 20,
        ]);

        $lowProduct = Product::factory()->create([
            'color_id' => $lowColor->id,
            'name' => '30',
            'product_code' => 'AG101-30',
            'current_amount' => 3, // 90m < 200m
        ]);
        $okProduct = Product::factory()->create([
            'color_id' => $okColor->id,
            'name' => '30',
            'product_code' => 'AG102-30',
            'current_amount' => 10, // 300m >= 200m
        ]);

        $component = Livewire::test(CreateCargo::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
            ])
            ->call('applyAutoOrder')
            ->assertDispatched('cargo-amounts-batch-synced')
            ->assertSet('orderCartOpen', true);

        $amounts = $component->get('data.cargo_amounts');
        $this->assertSame(15, (int) ($amounts[(string) $lowProduct->id] ?? 0));
        $this->assertSame(0, (int) ($amounts[(string) $okProduct->id] ?? 0));
    }

    public function test_expanded_cart_includes_stock_and_on_order_meters(): void
    {
        $this->actingAsAdmin();

        $supplier = Supplier::query()->create(['name' => 'Expanded Cart Supplier']);
        $collection = Collection::factory()->create([
            'name' => 'Catania',
            'supplier_id' => $supplier->id,
        ]);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '1102',
            'color_name' => 'Anthracite',
            'recommended_stock' => 200,
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'product_code' => 'CATANIA1102AN',
            'current_amount' => 2, // 60m < 200m
        ]);

        $otherCargo = Cargo::query()->create([
            'supplier_id' => $supplier->id,
            'status' => CargoStatus::Ordered,
            'date_shipped' => now()->toDateString(),
            'estimated_arrival' => now()->addWeeks(2)->toDateString(),
        ]);
        CargoItem::query()->create([
            'cargo_id' => $otherCargo->id,
            'product_id' => $product->id,
            'amount' => 7,
            'self_cost' => 0,
        ]);

        $component = Livewire::test(CreateCargo::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'cargo_amounts' => [
                    (string) $product->id => 10,
                ],
            ]);

        $items = $component->instance()->orderCartExpandedItems;
        $this->assertCount(1, $items);
        $this->assertSame(60.0, (float) $items[0]['stock_meters']);
        $this->assertTrue($items[0]['is_low_stock']);
        $this->assertSame(7, (int) $items[0]['on_order_rolls']);
        $this->assertSame(210.0, (float) $items[0]['on_order_meters']);

        $component
            ->call('mountAction', 'expandOrderCart')
            ->assertActionMounted('expandOrderCart');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-quick-order@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
