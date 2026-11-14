<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsForecast;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsForecastPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_forecast_page(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ReportsForecast::class)
            ->assertOk()
            ->assertSee('Stock forecast')
            ->assertSee('Forecast summary')
            ->assertSee('Lookback')
            ->assertSee('Horizon');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-forecast@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
