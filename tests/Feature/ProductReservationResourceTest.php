<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\ProductReservations\Pages\CreateProductReservation;
use App\Filament\Admin\Resources\ProductReservations\Pages\ListProductReservations;
use App\Filament\Admin\Resources\ProductReservations\ProductReservationResource;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductReservation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductReservationResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_reservations_page(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ListProductReservations::class)
            ->assertOk()
            ->assertSee('Reservations')
            ->assertSee('Add reservation');
    }

    public function test_admin_can_add_a_reservation(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create([
            'product_code' => 'RESERVE-1',
            'name' => '20',
            'current_amount' => 4,
        ]);

        Livewire::test(CreateProductReservation::class)
            ->fillForm([
                'product_id' => $product->id,
                'quantity' => 2,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_reservations', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
        $this->assertSame(4, (int) $product->fresh()->current_amount);
        $this->assertNotNull(ProductReservationResource::getUrl('index'));
    }

    public function test_catalog_products_are_not_offered_for_reservations(): void
    {
        $this->actingAsAdmin();

        $leather = Product::factory()->create(['product_code' => 'LEATHER-RES']);
        $catalog = Product::factory()->catalog()->create(['product_code' => 'CATALOG-RES']);

        Livewire::test(CreateProductReservation::class)
            ->assertFormFieldExists('product_id')
            ->fillForm([
                'product_id' => $catalog->id,
                'quantity' => 1,
            ])
            ->call('create')
            ->assertHasFormErrors(['product_id']);

        $this->assertDatabaseMissing('product_reservations', [
            'product_id' => $catalog->id,
        ]);

        Livewire::test(CreateProductReservation::class)
            ->fillForm([
                'product_id' => $leather->id,
                'quantity' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_reservations', [
            'product_id' => $leather->id,
            'quantity' => 1,
        ]);
    }

    public function test_reservation_cells_support_double_click_product_edits(): void
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
        $reservation = ProductReservation::factory()->create([
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $html = Livewire::test(ListProductReservations::class)->html();
        $this->assertStringContainsString('data-ss-cell-field="product_type"', $html);
        $this->assertStringContainsString('data-ss-cell-field="collection"', $html);
        $this->assertStringContainsString('data-ss-cell-field="color_name"', $html);
        $this->assertStringContainsString('data-ss-cell-field="color_code"', $html);
        $this->assertStringContainsString('data-ss-record-key="'.$reservation->id.'"', $html);

        Livewire::test(ListProductReservations::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => 'Blanc',
            ], arguments: [
                'recordKey' => (string) $reservation->id,
                'field' => 'color_name',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('Blanc', $color->fresh()?->color_name);

        Livewire::test(ListProductReservations::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => '103',
            ], arguments: [
                'recordKey' => (string) $reservation->id,
                'field' => 'color_code',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('103', $color->fresh()?->color_code);

        Livewire::test(ListProductReservations::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => $otherCollection->id,
            ], arguments: [
                'recordKey' => (string) $reservation->id,
                'field' => 'collection',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($otherCollection->id, (int) $color->fresh()?->collection_id);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-reservations@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
