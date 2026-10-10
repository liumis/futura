<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\ProductReservations\Pages\CreateProductReservation;
use App\Filament\Admin\Resources\ProductReservations\Pages\ListProductReservations;
use App\Filament\Admin\Resources\ProductReservations\ProductReservationResource;
use App\Models\Product;
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
