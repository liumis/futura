<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\CustomerLevel;
use App\Models\OriginCountry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserListPriceGroupFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_list_shows_price_group_and_filters_by_type_group_and_country(): void
    {
        $this->actingAsAdmin();

        $c1 = CustomerLevel::query()->create(['name' => 'C1']);
        $v = CustomerLevel::query()->create(['name' => 'V']);
        OriginCountry::query()->firstOrCreate(['name' => 'Lithuania']);
        OriginCountry::query()->firstOrCreate(['name' => 'Italy']);

        $customerLt = User::factory()->create([
            'email' => 'customer-lt@example.com',
            'company_name' => 'UAB Vinilas',
            'company_country' => 'Lithuania',
            'customer_level_id' => $c1->id,
        ]);
        $customerLt->assignRole(User::ROLE_CUSTOMER);

        $customerIt = User::factory()->create([
            'email' => 'customer-it@example.com',
            'company_name' => 'Italian Client',
            'company_country' => 'Italy',
            'customer_level_id' => $v->id,
        ]);
        $customerIt->assignRole(User::ROLE_CUSTOMER);

        $staff = User::factory()->create([
            'email' => 'staff-user@example.com',
            'company_name' => null,
            'company_country' => null,
            'customer_level_id' => null,
        ]);
        $staff->assignRole(User::ROLE_MANAGER);

        $list = Livewire::test(ListUsers::class);
        $html = $list->html();
        $this->assertStringContainsString('Price group', $html);
        $this->assertStringContainsString('Type: All', $html);
        $this->assertStringContainsString('Country: All', $html);

        // Default type filter is Customer.
        $list
            ->assertCanSeeTableRecords([$customerLt, $customerIt])
            ->assertCanNotSeeTableRecords([$staff])
            ->filterTable('party_type', 'staff')
            ->assertCanSeeTableRecords([$staff])
            ->assertCanNotSeeTableRecords([$customerLt, $customerIt])
            ->filterTable('party_type', null)
            ->filterTable('customer_level_id', (string) $v->id)
            ->assertCanSeeTableRecords([$customerIt])
            ->assertCanNotSeeTableRecords([$customerLt])
            ->filterTable('customer_level_id', null)
            ->filterTable('company_country', 'Lithuania')
            ->assertCanSeeTableRecords([$customerLt])
            ->assertCanNotSeeTableRecords([$customerIt])
            ->call('updateTableColumnState', 'customer_level_id', (string) $customerLt->id, (string) $v->id);

        $this->assertSame($v->id, $customerLt->fresh()?->customer_level_id);
    }

    private function actingAsAdmin(): User
    {
        foreach ([
            User::ROLE_ADMIN,
            User::ROLE_MANAGER,
            User::ROLE_CUSTOMER,
        ] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $admin = User::factory()->create(['email' => 'admin-users-list@example.com']);
        $admin->assignRole(User::ROLE_ADMIN);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
