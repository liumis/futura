<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsLowStock;
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

class ReportsLowStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_colors_below_their_recommended_stock(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Agnella']);
        $low = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Arctic',
            'recommended_stock' => 200,
        ]);
        $ok = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'White',
            'recommended_stock' => 100,
        ]);
        $zeroRecommended = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Black',
            'recommended_stock' => 0,
        ]);

        // 30m * 2 = 60m < 200 recommended
        Product::factory()->create([
            'color_id' => $low->id,
            'name' => '30',
            'current_amount' => 2,
        ]);
        // 30m * 4 = 120m >= 100 recommended
        Product::factory()->create([
            'color_id' => $ok->id,
            'name' => '30',
            'current_amount' => 4,
        ]);
        Product::factory()->create([
            'color_id' => $zeroRecommended->id,
            'name' => '30',
            'current_amount' => 0,
        ]);

        $ids = Color::query()->belowRecommendedStock()->pluck('id');

        $this->assertTrue($ids->contains($low->id));
        $this->assertFalse($ids->contains($ok->id));
        $this->assertFalse($ids->contains($zeroRecommended->id));

        Livewire::test(ReportsLowStock::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$ok, $zeroRecommended])
            ->assertSee('Arctic')
            ->assertSee('Total stock (m)');
    }

    public function test_aggregates_color_stock_across_products_and_ignores_catalog(): void
    {
        $collection = Collection::factory()->create();
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'recommended_stock' => 100,
        ]);

        Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'current_amount' => 2, // 60m
        ]);
        Product::factory()->create([
            'color_id' => $color->id,
            'name' => '20',
            'current_amount' => 1, // 20m → total 80m < 100
        ]);

        $catalogType = ProductType::query()->firstOrCreate(
            ['key' => ProductType::KEY_CATALOG],
            ['name' => 'Catalog', 'requires_color' => false],
        );
        Product::factory()->create([
            'color_id' => $color->id,
            'product_type_id' => $catalogType->id,
            'name' => 'Catalog',
            'current_amount' => 999,
        ]);

        $this->assertTrue(Color::query()->belowRecommendedStock()->whereKey($color->id)->exists());
        $this->assertSame(80.0, $color->fresh()->stockMeters());
    }

    public function test_excludes_catalog_only_colors(): void
    {
        $collection = Collection::factory()->create();
        $catalogColor = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Katalogas Agnona',
            'recommended_stock' => 200,
        ]);

        Product::factory()->catalog()->create([
            'color_id' => $catalogColor->id,
            'current_amount' => 0,
        ]);

        $this->assertFalse(
            Color::query()->belowRecommendedStock()->whereKey($catalogColor->id)->exists(),
        );
    }

    private function actingAsAdmin(): void
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
