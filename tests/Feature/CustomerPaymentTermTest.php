<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerPaymentTermTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_list_shows_payment_term_in_days(): void
    {
        $this->actingAsAdmin();
        Role::findOrCreate('customer');

        $customer = User::factory()->create([
            'email' => 'buyer-term@example.com',
            'payment_term' => 30,
        ]);
        $customer->assignRole('customer');

        Livewire::test(ListUsers::class)
            ->assertOk()
            ->assertSee('Payment term')
            ->assertSee('30 days');
    }

    public function test_admin_can_set_payment_term_when_creating_a_customer(): void
    {
        $this->actingAsAdmin();
        $customerRole = Role::findOrCreate('customer');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Ada',
                'surname' => 'Buyer',
                'email' => 'ada-term@example.com',
                'password' => 'password',
                'roles' => [$customerRole->getKey()],
                'payment_term' => 14,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'ada-term@example.com',
            'payment_term' => 14,
        ]);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-payment-term@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
