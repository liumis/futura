<?php

namespace Tests\Feature;

use App\Enums\AdminModule;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\EditProfile;
use App\Filament\Admin\Pages\Invoices;
use App\Models\User;
use App\Services\AdminHomepage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminHomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_homepage_is_dashboard(): void
    {
        $admin = $this->actingAsAdmin();

        $this->assertNull($admin->homepage_module);
        $this->assertSame(Dashboard::getUrl(), AdminHomepage::urlFor($admin));
        $this->assertSame(Dashboard::getUrl(), Filament::getHomeUrl());
    }

    public function test_finances_homepage_opens_invoices(): void
    {
        $admin = $this->actingAsAdmin();
        $admin->forceFill(['homepage_module' => AdminModule::Finances->value])->save();

        $this->assertSame(Invoices::getUrl(), AdminHomepage::urlFor($admin->fresh()));
        $this->assertSame(Invoices::getUrl(), $admin->fresh()->homepageUrl());
    }

    public function test_inaccessible_homepage_falls_back_to_dashboard(): void
    {
        Role::findOrCreate('project manager');
        $pm = User::factory()->create(['email' => 'pm-homepage@example.com']);
        $pm->assignRole('project manager');
        $pm->forceFill(['homepage_module' => AdminModule::Finances->value])->save();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($pm);

        $this->assertFalse($pm->canAccessModule(AdminModule::Finances));
        $this->assertSame(Dashboard::getUrl(), AdminHomepage::urlFor($pm));
    }

    public function test_profile_can_save_homepage_module(): void
    {
        $this->actingAsAdmin();

        Livewire::test(EditProfile::class)
            ->assertOk()
            ->assertSee('Homepage')
            ->fillForm([
                'name' => 'Admin',
                'surname' => 'User',
                'email' => 'admin-homepage@example.com',
                'homepage_module' => AdminModule::Tasks->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            AdminModule::Tasks->value,
            User::query()->where('email', 'admin-homepage@example.com')->value('homepage_module')
        );
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-homepage@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
