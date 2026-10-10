<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsIsaf;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRate;
use App\Services\IsafXmlExporter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsIsafExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-isaf@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_open_page_and_see_download_button(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsIsaf::class)
            ->assertOk()
            ->assertSee('Download i.SAF XML')
            ->assertSee('i.SAF export')
            ->assertSee('i.SAF 1.2');
    }

    public function test_page_lists_period_sales_invoice(): void
    {
        $this->actingAs($this->admin);

        $period = now()->subMonthNoOverflow()->startOfMonth()->addDays(9);

        $contact = Contact::query()->create([
            'company_name' => 'Buyer UAB',
            'company_id' => '100000001',
            'company_vat' => 'LT100000001',
            'company_address' => 'Vilnius',
        ]);
        $rate = VatRate::query()->create([
            'classificator' => 'PVM1',
            'rate' => 21,
        ]);
        $income = IncomeType::query()->create(['name' => 'Sales']);

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'ISAF-S-PAGE',
            'invoice_date' => $period->toDateString(),
            'upload_date' => $period->toDateString(),
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'vat_rate_id' => $rate->getKey(),
            'income_type_id' => $income->getKey(),
        ]);

        Livewire::test(ReportsIsaf::class)
            ->assertOk()
            ->assertSee('ISAF-S-PAGE')
            ->assertSee('Sales invoices')
            ->assertSee('PVM1 (21.00%)');
    }

    public function test_download_without_company_id_notifies_failure(): void
    {
        $this->actingAs($this->admin);

        CompanySetting::query()->create(['company_name' => 'UAB Test']);

        Livewire::test(ReportsIsaf::class)
            ->callAction('downloadXml')
            ->assertNotified('Could not build i.SAF file');
    }

    public function test_download_returns_xml_file(): void
    {
        $this->actingAs($this->admin);

        CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_id' => '123456789',
        ]);

        $from = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $until = now()->subMonthNoOverflow()->endOfMonth()->toDateString();

        Livewire::test(ReportsIsaf::class)
            ->callAction('downloadXml')
            ->assertFileDownloaded(
                app(IsafXmlExporter::class)->fileName([
                    'from' => $from,
                    'until' => $until,
                    'data_type' => IsafXmlExporter::DATA_FULL,
                ]),
            );
    }
}
