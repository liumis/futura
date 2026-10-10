<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsProfitLoss;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceCode;
use App\Models\InvoiceFinanceLine;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsProfitLossGenerateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-profit-loss@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_open_page_and_see_generate_button(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsProfitLoss::class)
            ->assertOk()
            ->assertSee('Generate report')
            ->assertSee('Profit (loss) statement')
            ->assertSee('Pelno (nuostolių) ataskaita');
    }

    public function test_statement_uses_period_income_and_expense_accounts(): void
    {
        $this->actingAs($this->admin);

        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '444',
            'company_address' => 'Vilnius',
        ]);
        $sales = InvoiceCode::query()->create([
            'code' => '5000',
            'name' => 'Parduotų prekių pajamos',
        ]);
        $cost = InvoiceCode::query()->create([
            'code' => '6000',
            'name' => 'Parduotų prekių savikaina',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'PL-1',
            'invoice_date' => now()->toDateString(),
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
        ]);

        InvoiceFinanceLine::query()->create([
            'invoice_id' => $invoice->getKey(),
            'invoice_code_id' => $sales->getKey(),
            'name' => $sales->name,
            'debit' => 0,
            'credit' => 100,
            'sort_order' => 1,
        ]);
        InvoiceFinanceLine::query()->create([
            'invoice_id' => $invoice->getKey(),
            'invoice_code_id' => $cost->getKey(),
            'name' => $cost->name,
            'debit' => 40,
            'credit' => 0,
            'sort_order' => 2,
        ]);

        Livewire::test(ReportsProfitLoss::class)
            ->assertOk()
            ->assertSee('Sales revenue')
            ->assertSee('Pardavimo pajamos')
            ->assertSee('100.00')
            ->assertSee('40.00')
            ->assertSee('60.00');
    }

    public function test_generate_without_sharepoint_notifies_failure(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsProfitLoss::class)
            ->callAction('generateReport')
            ->assertNotified('Could not generate report');
    }
}
