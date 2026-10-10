<?php

namespace Tests\Feature;

use App\Enums\EmployeeNpdType;
use App\Filament\Admin\Pages\ReportsGpm313;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsGpm313GenerateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00'));
        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-gpm313@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        CompanySetting::query()->create([
            'company_name' => 'UAB FUTURA TEXTILES EUROPE',
            'company_id' => '306740042',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_open_page_and_see_generate_button(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsGpm313::class)
            ->assertOk()
            ->assertSee('Generate report')
            ->assertSee('GPM313 report')
            ->assertSee('Mėnesinė pajamų mokesčio deklaracija');
    }

    public function test_summary_splits_gpm_by_payment_date(): void
    {
        $this->actingAs($this->admin);

        $first = Employee::query()->create([
            'name' => 'Jonas',
            'surname' => 'Ankstyvas',
            'working_time_percentage' => 100,
            'npd_type' => EmployeeNpdType::Standard,
        ]);
        $second = Employee::query()->create([
            'name' => 'Ona',
            'surname' => 'Velyva',
            'working_time_percentage' => 100,
            'npd_type' => EmployeeNpdType::Standard,
        ]);

        EmployeeMonthlyPayment::query()->create([
            'employee_id' => $first->getKey(),
            'payment_date' => '2026-07-10',
            'gross_amount' => 5000,
            'gpm_amount' => 800,
            'status' => 'open',
        ]);
        EmployeeMonthlyPayment::query()->create([
            'employee_id' => $second->getKey(),
            'payment_date' => '2026-07-31',
            'gross_amount' => 4878.99,
            'gpm_amount' => 1175.80,
            'status' => 'payed',
        ]);

        Livewire::test(ReportsGpm313::class)
            ->fillForm(['month' => '2026-07'])
            ->assertOk()
            ->assertSee('Jonas Ankstyvas')
            ->assertSee('Ona Velyva')
            ->assertSee('Until 15th (6)')
            ->assertSee('From 16th (7)')
            ->assertSee('9 878,99')
            ->assertSee('800,00')
            ->assertSee('1 175,80');
    }

    public function test_generate_without_sharepoint_notifies_failure(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsGpm313::class)
            ->callAction('generateReport')
            ->assertNotified('Could not generate report');
    }
}
