<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Invoices;
use App\Models\Contact;
use App\Models\ExpenseType;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceInlineCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoices_list_can_change_category_inline(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Category Co',
            'company_id' => '111',
            'company_address' => 'Vilnius',
        ]);
        $income = IncomeType::query()->create(['name' => 'Prekės']);
        $expense = ExpenseType::query()->create(['name' => 'Nuoma']);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-CAT-1',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
        ]);

        Livewire::test(Invoices::class)
            ->assertOk()
            ->assertSee('INV-CAT-1')
            ->assertSeeHtml('wire:change="updateFinanceCategory('.$invoice->getKey())
            ->assertSee('Missing type')
            ->assertSee('Prekės')
            ->assertSee('Nuoma')
            ->assertSee(Invoice::OWN_ACCOUNT_LABEL)
            ->call('updateFinanceCategory', $invoice->getKey(), 'income:'.$income->getKey())
            ->assertSeeHtml('invoice-category-select--income');

        $invoice->refresh();
        $this->assertSame($income->getKey(), $invoice->income_type_id);
        $this->assertNull($invoice->expense_type_id);
        $this->assertFalse((bool) $invoice->is_own_account);
        $this->assertSame('income:'.$income->getKey(), $invoice->storedFinanceTypeValue());

        Livewire::test(Invoices::class)
            ->call('updateFinanceCategory', $invoice->getKey(), 'expense:'.$expense->getKey());

        $invoice->refresh();
        $this->assertNull($invoice->income_type_id);
        $this->assertSame($expense->getKey(), $invoice->expense_type_id);
        $this->assertFalse((bool) $invoice->is_own_account);

        Livewire::test(Invoices::class)
            ->call('updateFinanceCategory', $invoice->getKey(), Invoice::OWN_ACCOUNT_FILTER);

        $invoice->refresh();
        $this->assertTrue((bool) $invoice->is_own_account);
        $this->assertNull($invoice->income_type_id);
        $this->assertNull($invoice->expense_type_id);
        $this->assertSame(Invoice::OWN_ACCOUNT_FILTER, $invoice->storedFinanceTypeValue());

        Livewire::test(Invoices::class)
            ->call('updateFinanceCategory', $invoice->getKey(), '');

        $invoice->refresh();
        $this->assertFalse((bool) $invoice->is_own_account);
        $this->assertNull($invoice->income_type_id);
        $this->assertNull($invoice->expense_type_id);
        $this->assertNull($invoice->storedFinanceTypeValue());
    }

    public function test_inline_category_ignores_unknown_values(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Category Co',
            'company_id' => '222',
            'company_address' => 'Vilnius',
        ]);
        $income = IncomeType::query()->create(['name' => 'Paslaugos']);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-CAT-2',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 50,
            'vat' => 0,
            'sum_inc_vat' => 50,
            'income_type_id' => $income->getKey(),
        ]);

        Livewire::test(Invoices::class)
            ->call('updateFinanceCategory', $invoice->getKey(), 'income:99999');

        $invoice->refresh();
        $this->assertSame($income->getKey(), $invoice->income_type_id);
    }

    public function test_invoice_number_can_be_updated_inline(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Number Co',
            'company_id' => '333',
            'company_address' => 'Vilnius',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-OLD',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ]);

        Livewire::test(Invoices::class)
            ->call('updateInvoiceNumber', $invoice->getKey(), 'INV-NEW')
            ->assertOk();

        $this->assertSame('INV-NEW', $invoice->fresh()->invoice_number);
    }

    public function test_inline_invoice_number_rejects_duplicates(): void
    {
        $admin = $this->actingAsAdmin();
        $contact = Contact::query()->create([
            'company_name' => 'Number Co',
            'company_id' => '444',
            'company_address' => 'Vilnius',
        ]);

        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-TAKEN',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-OTHER',
            'invoice_date' => '2026-08-02',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 20,
            'vat' => 0,
            'sum_inc_vat' => 20,
        ]);

        Livewire::test(Invoices::class)
            ->call('updateInvoiceNumber', $invoice->getKey(), 'INV-TAKEN')
            ->assertNotified('Invoice number already exists');

        $this->assertSame('INV-OTHER', $invoice->fresh()->invoice_number);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-invoice-category@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
