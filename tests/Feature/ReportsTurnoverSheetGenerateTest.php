<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsTurnoverSheet;
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

class ReportsTurnoverSheetGenerateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-turnover@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_open_page_and_see_generate_button(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsTurnoverSheet::class)
            ->assertOk()
            ->assertSee('Generate report')
            ->assertSee('Recalculate from 2026-04-01')
            ->assertSee('Filters')
            ->assertSee('Turnover sheet')
            ->assertSee('Ledger coverage');
    }

    public function test_table_splits_opening_turnover_and_closing(): void
    {
        $this->actingAs($this->admin);

        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '333',
            'company_address' => 'Vilnius',
        ]);
        $code = InvoiceCode::query()->create([
            'code' => '1',
            'name' => 'ILGALAIKIS TURTAS',
        ]);

        $this->makeInvoiceLine($contact, $code, 'FT-TS-OPEN', '2026-07-15', debit: 100, credit: 0);
        $this->makeInvoiceLine($contact, $code, 'FT-TS-PERIOD', '2026-08-10', debit: 20, credit: 40);

        Livewire::test(ReportsTurnoverSheet::class)
            ->set('data.from', '2026-08-01')
            ->set('data.until', '2026-08-31')
            ->assertOk()
            ->assertSee('ILGALAIKIS TURTAS')
            ->assertSee('100.00')
            ->assertSee('20.00')
            ->assertSee('40.00')
            ->assertSee('80.00');
    }

    public function test_generate_without_sharepoint_notifies_failure(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsTurnoverSheet::class)
            ->callAction('generateReport')
            ->assertNotified('Could not generate report');
    }

    private function makeInvoiceLine(
        Contact $contact,
        InvoiceCode $code,
        string $invoiceNumber,
        string $invoiceDate,
        float $debit,
        float $credit,
    ): void {
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'upload_date' => $invoiceDate,
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => $debit + $credit,
            'vat' => 0,
            'sum_inc_vat' => $debit + $credit,
        ]);

        InvoiceFinanceLine::query()->create([
            'invoice_id' => $invoice->getKey(),
            'invoice_code_id' => $code->getKey(),
            'name' => $code->name,
            'debit' => $debit,
            'credit' => $credit,
            'sort_order' => 1,
        ]);
    }
}
