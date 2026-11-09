<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Dashboard;
use App\Models\DirectoryContact;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardNeverContactedContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_lists_new_never_contacted_contacts(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-never-contact@example.com']);
        $admin->assignRole('admin');

        DirectoryContact::query()->create([
            'company_name' => 'Already contacted AB',
            'country' => 'Sweden',
            'last_contact' => now()->subDay(),
        ]);

        $fresh = DirectoryContact::query()->create([
            'company_name' => 'Biskinis Bros S.A.',
            'country' => 'Greece',
            'email' => 'info@boats.gr',
            'last_contact' => null,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $rows = Livewire::test(Dashboard::class)
            ->instance()
            ->getNewNeverContactedContacts();

        $this->assertTrue($rows->contains(fn (array $row): bool => $row['contact']->is($fresh)));
        $this->assertFalse($rows->contains(
            fn (array $row): bool => $row['contact']->company_name === 'Already contacted AB'
        ));
    }
}
