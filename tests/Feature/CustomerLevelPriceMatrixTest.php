<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\CustomerLevels\Pages\ListCustomerLevels;
use App\Models\Collection;
use App\Models\CustomerLevel;
use App\Models\CustomerLevelPrice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerLevelPriceMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_matrix_upserts_and_clears_prices(): void
    {
        $this->actingAsAdmin();

        $level = CustomerLevel::factory()->create(['name' => 'Wholesale']);
        $agnona = Collection::factory()->create(['name' => 'Agnona', 'price' => 10]);
        $ferrara = Collection::factory()->create(['name' => 'Ferrara', 'price' => 12]);

        Livewire::test(ListCustomerLevels::class)
            ->assertOk()
            ->assertSee('Customer group')
            ->assertSee('Agnona')
            ->assertSee('Wholesale')
            ->call('savePrice', $level->id, $agnona->id, '8,5')
            ->call('savePrice', $level->id, $ferrara->id, '9.9');

        $this->assertDatabaseHas('customer_level_prices', [
            'customer_level_id' => $level->id,
            'collection_id' => $agnona->id,
            'price' => 8.5,
        ]);
        $this->assertDatabaseHas('customer_level_prices', [
            'customer_level_id' => $level->id,
            'collection_id' => $ferrara->id,
            'price' => 9.9,
        ]);

        Livewire::test(ListCustomerLevels::class)
            ->call('savePrice', $level->id, $agnona->id, '');

        $this->assertDatabaseMissing('customer_level_prices', [
            'customer_level_id' => $level->id,
            'collection_id' => $agnona->id,
        ]);
    }

    public function test_matrix_can_rename_and_delete_level(): void
    {
        $this->actingAsAdmin();

        $level = CustomerLevel::factory()->create(['name' => 'Old name']);
        $collection = Collection::factory()->create(['name' => 'Palermo']);
        CustomerLevelPrice::factory()->create([
            'customer_level_id' => $level->id,
            'collection_id' => $collection->id,
            'price' => 4.5,
        ]);

        Livewire::test(ListCustomerLevels::class)
            ->call('renameLevel', $level->id, 'VIP')
            ->call('deleteLevel', $level->id);

        $this->assertDatabaseMissing('customer_levels', ['id' => $level->id]);
        $this->assertDatabaseMissing('customer_level_prices', [
            'customer_level_id' => $level->id,
        ]);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-price-matrix@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
