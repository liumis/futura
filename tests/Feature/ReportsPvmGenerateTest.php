<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsPvm;
use App\Models\Contact;
use App\Models\ExpenseType;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRate;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsPvmGenerateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-pvm@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_open_page_and_see_generate_button(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsPvm::class)
            ->assertOk()
            ->assertSee('Generate report')
            ->assertSee('PVM report')
            ->assertSee('Pridėtinės vertės mokestis');
    }

    public function test_summary_uses_sales_output_and_purchase_input_vat(): void
    {
        $this->actingAs($this->admin);

        $contact = Contact::query()->create([
            'company_name' => 'Supplier UAB',
            'company_id' => '666',
            'company_address' => 'Vilnius',
        ]);
        $rate = VatRate::query()->create([
            'classificator' => 'PVM1',
            'rate' => 21,
        ]);
        $income = IncomeType::query()->create(['name' => 'Sales']);
        $expense = ExpenseType::query()->create(['name' => 'Purchases']);

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'PVM-S-2',
            'invoice_date' => now()->toDateString(),
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'vat_rate_id' => $rate->getKey(),
            'income_type_id' => $income->getKey(),
        ]);

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'PVM-P-2',
            'invoice_date' => now()->toDateString(),
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 50,
            'vat' => 10.50,
            'sum_inc_vat' => 60.50,
            'vat_rate_id' => $rate->getKey(),
            'expense_type_id' => $expense->getKey(),
        ]);

        Livewire::test(ReportsPvm::class)
            ->assertOk()
            ->assertSee('PVM-S-2')
            ->assertSee('PVM-P-2')
            ->assertSee('Output VAT')
            ->assertSee('Input VAT')
            ->assertSee('PVM payable')
            ->assertSee('PVM1 (21.00%)');
    }

    public function test_generate_without_sharepoint_notifies_failure(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ReportsPvm::class)
            ->callAction('generateReport')
            ->assertNotified('Could not generate report');
    }
}
