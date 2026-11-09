<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Models\Color;
use App\Models\Collection;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductListDoubleClickCellEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_list_supports_double_click_color_and_code_edits(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '101',
            'color_name' => 'White',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'product_code' => 'AG101-30',
            'name' => '30',
        ]);

        $html = Livewire::test(ListProducts::class)->html();
        $this->assertStringContainsString('data-ss-cell-field="color_name"', $html);
        $this->assertStringContainsString('data-ss-cell-field="color_code"', $html);
        $this->assertStringContainsString('data-ss-cell-field="name"', $html);
        $this->assertStringContainsString('data-ss-cell-field="product_code"', $html);
        $this->assertStringContainsString('data-ss-cell-field="cost"', $html);
        $this->assertStringContainsString('data-ss-record-key="'.$product->id.'"', $html);

        Livewire::test(ListProducts::class)
            ->callAction('doubleClickCellEdit', data: ['value' => 'Blanc'], arguments: [
                'recordKey' => (string) $product->id,
                'field' => 'color_name',
            ])
            ->assertHasNoActionErrors();
        $this->assertSame('Blanc', $color->fresh()?->color_name);

        Livewire::test(ListProducts::class)
            ->callAction('doubleClickCellEdit', data: ['value' => '103'], arguments: [
                'recordKey' => (string) $product->id,
                'field' => 'color_code',
            ])
            ->assertHasNoActionErrors();
        $this->assertSame('103', $color->fresh()?->color_code);

        Livewire::test(ListProducts::class)
            ->callAction('doubleClickCellEdit', data: ['value' => 'Saramo katalogas'], arguments: [
                'recordKey' => (string) $product->id,
                'field' => 'name',
            ])
            ->assertHasNoActionErrors();
        $this->assertSame('Saramo katalogas', $product->fresh()?->name);

        Livewire::test(ListProducts::class)
            ->callAction('doubleClickCellEdit', data: ['value' => 'AG103-30'], arguments: [
                'recordKey' => (string) $product->id,
                'field' => 'product_code',
            ])
            ->assertHasNoActionErrors();
        $this->assertSame('AG103-30', $product->fresh()?->product_code);

        Livewire::test(ListProducts::class)
            ->callAction('doubleClickCellEdit', data: ['value' => '2,70'], arguments: [
                'recordKey' => (string) $product->id,
                'field' => 'cost',
            ])
            ->assertHasNoActionErrors();
        $this->assertSame(2.7, (float) $product->fresh()?->cost);
    }

    public function test_bootstrap_applies_standard_collection_and_catalog_prices(): void
    {
        $collection = Collection::factory()->create(['name' => 'Agnona', 'price' => 5]);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => 'KAT',
            'color_name' => 'Catalog',
        ]);
        $typeId = \App\Models\ProductType::query()->where('key', 'catalog')->value('id')
            ?? \App\Models\ProductType::query()->create([
                'key' => 'catalog',
                'name' => 'Catalog',
            ])->id;

        $catalog = Product::factory()->create([
            'color_id' => $color->id,
            'product_type_id' => $typeId,
            'product_code' => '/AGNONA/KATALOGAI',
            'cost' => 0,
        ]);

        $result = app(\App\Services\FuturaCustomerLevelBootstrap::class)->run();

        $this->assertSame(6.9, (float) $collection->fresh()->price);
        $this->assertSame(2.7, (float) $catalog->fresh()->cost);
        $this->assertGreaterThanOrEqual(1, $result['collections_updated']);
        $this->assertGreaterThanOrEqual(1, $result['catalog_products_updated']);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');

        $admin = User::factory()->create(['email' => 'admin-products-cell@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
