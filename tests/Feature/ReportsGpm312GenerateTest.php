<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsGpm312;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Dividend;
use App\Models\Invoice;
use App\Models\Shareholder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsGpm312GenerateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00'));
        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-gpm312@example.com',
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

        Livewire::test(ReportsGpm312::class)
            ->assertOk()
            ->assertSee('Generate report')
            ->assertSee('GPM312 report')
            ->assertSee('Metinė A ir B klasės išmokų deklaracija');
    }

    public function test_summary_uses_dividend_payments_for_the_year(): void
    {
        $this->actingAs($this->admin);

        $shareholder = Shareholder::query()->create([
            'name' => 'Jonas Jonaitis',
            'shareholder_percentage' => 100,
        ]);

        Dividend::query()->create([
            'shareholder_id' => $shareholder->getKey(),
            'date' => '2026-03-10',
            'amount' => 2500,
            'status' => 'payed',
        ]);

        Livewire::test(ReportsGpm312::class)
            ->fillForm(['year' => '2026'])
            ->assertOk()
            ->assertSee('Jonas Jonaitis')
            ->assertSee('2 500,00')
            ->assertSee('500,00');
    }

    public function test_summary_includes_private_provider_invoices(): void
    {
        $this->actingAs($this->admin);

        $contact = Contact::query()->create([
            'company_name' => 'Private tailor',
            'company_id' => '39001112222',
            'company_address' => 'Kaunas',
            'private_provider' => true,
        ]);

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'PP-GPM-1',
            'invoice_date' => '2026-04-12',
            'upload_date' => '2026-04-12',
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 320,
            'vat' => 0,
            'sum_inc_vat' => 320,
        ]);

        Livewire::test(ReportsGpm312::class)
            ->fillForm(['year' => '2026'])
            ->assertOk()
            ->assertSee('Private provider invoices')
            ->assertSee('Private tailor')
            ->assertSee('PP-GPM-1')
            ->assertSee('320,00');
    }

    public function test_generate_without_sharepoint_notifies_failure(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsGpm312::class)
            ->fillForm(['year' => '2026'])
            ->callAction('generateReport')
            ->assertNotified('Could not generate report');
    }
}
