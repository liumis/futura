<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsBalance;
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

class ReportsBalanceGenerateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-balance@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_open_page_and_see_generate_button(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsBalance::class)
            ->assertOk()
            ->assertSee('Generate report')
            ->assertSee('Filters')
            ->assertSee('Balance');
    }

    public function test_table_groups_finance_lines_by_account_code(): void
    {
        $this->actingAs($this->admin);

        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '222',
            'company_address' => 'Vilnius',
        ]);
        $code = InvoiceCode::query()->create([
            'code' => '1',
            'name' => 'ILGALAIKIS TURTAS',
        ]);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'FT2000001',
            'invoice_date' => now()->toDateString(),
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 30,
            'vat' => 0,
            'sum_inc_vat' => 30,
        ]);

        InvoiceFinanceLine::query()->create([
            'invoice_id' => $invoice->getKey(),
            'invoice_code_id' => $code->getKey(),
            'name' => 'ILGALAIKIS TURTAS',
            'debit' => 20,
            'credit' => 0,
            'sort_order' => 1,
        ]);
        InvoiceFinanceLine::query()->create([
            'invoice_id' => $invoice->getKey(),
            'invoice_code_id' => $code->getKey(),
            'name' => 'ILGALAIKIS TURTAS',
            'debit' => 10,
            'credit' => 5,
            'sort_order' => 2,
        ]);

        Livewire::test(ReportsBalance::class)
            ->assertOk()
            ->assertSee('ILGALAIKIS TURTAS')
            ->assertSee('30.00')
            ->assertSee('5.00');
    }

    public function test_generate_without_sharepoint_notifies_failure(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsBalance::class)
            ->callAction('generateReport')
            ->assertNotified('Could not generate report');
    }
}
