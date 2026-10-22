<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\StockManualUpdates\Pages\ListStockManualUpdates;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Models\StockManualUpdate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockManualUpdateDoubleClickCellEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_off_history_cells_support_double_click_edits(): void
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
            'name' => '30',
        ]);
        $update = StockManualUpdate::query()->create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'old_amount' => 5,
            'new_amount' => 3,
            'comment' => 'Test write-off',
        ]);

        $html = Livewire::test(ListStockManualUpdates::class)->html();
        $this->assertStringContainsString('data-ss-cell-field="collection"', $html);
        $this->assertStringContainsString('data-ss-cell-field="color_name"', $html);
        $this->assertStringContainsString('data-ss-cell-field="product_code"', $html);
        $this->assertStringContainsString('data-ss-record-key="'.$update->id.'"', $html);

        Livewire::test(ListStockManualUpdates::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => 'Blanc',
            ], arguments: [
                'recordKey' => (string) $update->id,
                'field' => 'color_name',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('Blanc', $color->fresh()?->color_name);

        Livewire::test(ListStockManualUpdates::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => 'AG101-NEW',
            ], arguments: [
                'recordKey' => (string) $update->id,
                'field' => 'product_code',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('AG101-NEW', $product->fresh()?->product_code);

        Livewire::test(ListStockManualUpdates::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => $otherCollection->id,
            ], arguments: [
                'recordKey' => (string) $update->id,
                'field' => 'collection',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($otherCollection->id, (int) $color->fresh()?->collection_id);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');

        $admin = User::factory()->create(['email' => 'admin-writeoff-cell@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
