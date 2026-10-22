<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Stock;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockDoubleClickCellEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_cells_are_marked_for_double_click_edit(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create([
            'product_code' => 'AG101-30',
        ]);

        $html = Livewire::test(Stock::class)->html();

        $this->assertStringContainsString('data-ss-cell-edit="1"', $html);
        $this->assertStringContainsString('data-ss-cell-field="product_code"', $html);
        $this->assertStringContainsString('data-ss-cell-field="product_type"', $html);
        $this->assertStringContainsString('data-ss-record-key="'.$product->id.'"', $html);
        $this->assertFileExists(public_path('js/ss-double-click-cell-edit.js'));
    }

    public function test_double_click_edit_updates_product_code(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create([
            'product_code' => 'OLD-CODE',
        ]);

        Livewire::test(Stock::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => 'NEW-CODE-99',
            ], arguments: [
                'recordKey' => (string) $product->id,
                'field' => 'product_code',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('NEW-CODE-99', $product->fresh()?->product_code);
    }

    public function test_double_click_edit_updates_shared_color_fields(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $otherCollection = Collection::factory()->create(['name' => 'Paloma']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_code' => '101',
            'color_name' => 'White',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->id,
            'product_code' => 'AG101-30',
        ]);

        Livewire::test(Stock::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => 'Blanc',
            ], arguments: [
                'recordKey' => (string) $product->id,
                'field' => 'color_name',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('Blanc', $color->fresh()?->color_name);

        Livewire::test(Stock::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => '103',
            ], arguments: [
                'recordKey' => (string) $product->id,
                'field' => 'color_code',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('103', $color->fresh()?->color_code);

        Livewire::test(Stock::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => $otherCollection->id,
            ], arguments: [
                'recordKey' => (string) $product->id,
                'field' => 'collection',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($otherCollection->id, (int) $color->fresh()?->collection_id);
    }

    public function test_double_click_edit_updates_product_type(): void
    {
        $this->actingAsAdmin();

        $leatherId = ProductType::artificialLeatherId()
            ?? ProductType::query()->create([
                'name' => 'Artificial leather',
                'key' => ProductType::KEY_ARTIFICIAL_LEATHER,
                'requires_color' => true,
            ])->id;
        $catalogId = ProductType::catalogId()
            ?? ProductType::query()->create([
                'name' => 'Catalog',
                'key' => ProductType::KEY_CATALOG,
                'requires_color' => false,
            ])->id;

        $product = Product::factory()->create([
            'product_type_id' => $leatherId,
        ]);

        Livewire::test(Stock::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => $catalogId,
            ], arguments: [
                'recordKey' => (string) $product->id,
                'field' => 'product_type',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($catalogId, (int) $product->fresh()?->product_type_id);
    }

    public function test_color_fields_blocked_for_catalog_without_color(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->catalog()->create([
            'product_code' => 'CAT-1',
        ]);

        Livewire::test(Stock::class)
            ->call('openDoubleClickCellEdit', (string) $product->id, 'color_name')
            ->assertActionNotMounted('doubleClickCellEdit')
            ->assertNotified();
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');

        $admin = User::factory()->create(['email' => 'admin-stock-cell@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
