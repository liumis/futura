<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsFr0600;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRate;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsFr0600GenerateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00'));
        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-fr0600@example.com',
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

        Livewire::test(ReportsFr0600::class)
            ->assertOk()
            ->assertSee('Generate PDF')
            ->assertSee('FR0600 report')
            ->assertSee('Mokestinio laikotarpio PVM deklaracija');
    }

    public function test_summary_uses_month_invoices(): void
    {
        $this->actingAs($this->admin);

        $contact = Contact::query()->create([
            'company_name' => 'Buyer UAB',
            'company_id' => '222',
            'company_address' => 'Vilnius',
        ]);
        $rate = VatRate::query()->create([
            'classificator' => 'PVM1',
            'rate' => 21,
        ]);
        $income = IncomeType::query()->create(['name' => 'Sales']);

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'FR-S-1',
            'invoice_date' => '2026-07-10',
            'upload_date' => '2026-07-10',
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 1000,
            'vat' => 210,
            'sum_inc_vat' => 1210,
            'vat_rate_id' => $rate->getKey(),
            'income_type_id' => $income->getKey(),
        ]);

        Livewire::test(ReportsFr0600::class)
            ->fillForm(['month' => '2026-07'])
            ->assertOk()
            ->assertSee('FR-S-1')
            ->assertSee('Buyer UAB')
            ->assertSee('1 000,00')
            ->assertSee('210,00');
    }

    public function test_generate_without_sharepoint_notifies_failure(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsFr0600::class)
            ->callAction('generateReport')
            ->assertNotified('Could not generate report');
    }
}
