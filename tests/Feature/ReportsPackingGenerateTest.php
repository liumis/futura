<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsPacking;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsPackingGenerateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-packing@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_open_page_with_internal_filter_and_generate_buttons(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsPacking::class)
            ->assertOk()
            ->assertSee('Generate internal')
            ->assertSee('Generate export')
            ->assertSee('Customer type')
            ->assertSee('Internal');
    }

    public function test_internal_filter_hides_export_customers(): void
    {
        $this->actingAs($this->admin);

        $internal = User::factory()->create([
            'company_name' => 'Local Shop UAB',
            'export' => false,
        ]);
        $export = User::factory()->create([
            'company_name' => 'Export Partner GmbH',
            'export' => true,
        ]);

        Order::factory()->create([
            'user_id' => $internal->getKey(),
            'order_date' => now(),
        ]);
        Order::factory()->create([
            'user_id' => $export->getKey(),
            'order_date' => now(),
        ]);

        Livewire::test(ReportsPacking::class)
            ->set('data.export', 'internal')
            ->assertSee('Local Shop UAB')
            ->assertDontSee('Export Partner GmbH');

        Livewire::test(ReportsPacking::class)
            ->set('data.export', 'export')
            ->assertSee('Export Partner GmbH')
            ->assertDontSee('Local Shop UAB');
    }

    public function test_generate_internal_and_export_without_sharepoint_notify_failure(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsPacking::class)
            ->callAction('generateInternalReport')
            ->assertNotified('Could not generate report');

        Livewire::test(ReportsPacking::class)
            ->callAction('generateExportReport')
            ->assertNotified('Could not generate report');
    }
}
