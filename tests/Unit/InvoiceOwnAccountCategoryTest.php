<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\ExpenseType;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceOwnAccountCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_account_is_neither_income_nor_expense(): void
    {
        $contact = Contact::query()->create([
            'company_name' => 'Futura',
            'company_id' => '1',
            'company_address' => '—',
        ]);
        $user = User::factory()->create();

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->id,
            'invoice_number' => 'Tarp savo sąskaitų (Between account)',
            'invoice_date' => '2026-07-30',
            'sum_without_vat' => 974,
            'vat' => 0,
            'sum_inc_vat' => 974,
            'is_own_account' => true,
            'income_type_id' => IncomeType::query()->create(['name' => 'Prekės'])->id,
            'expense_type_id' => ExpenseType::query()->create(['name' => 'Prekės'])->id,
            'upload_date' => '2026-07-30',
            'uploaded_by' => $user->id,
        ]);

        $parsed = Invoice::parseFinanceTypeFilterValue(Invoice::OWN_ACCOUNT_FILTER);
        $invoice->forceFill($parsed)->save();

        $invoice->refresh();
        $this->assertTrue($invoice->is_own_account);
        $this->assertNull($invoice->income_type_id);
        $this->assertNull($invoice->expense_type_id);
        $this->assertSame(Invoice::OWN_ACCOUNT_FILTER, $invoice->financeCategoryKind());
        $this->assertSame(Invoice::OWN_ACCOUNT_LABEL, $invoice->financeCategoryName());
        $this->assertSame(Invoice::OWN_ACCOUNT_FILTER, $invoice->financeTypeFilterValue());
    }

    public function test_suggested_category_detects_between_account_invoice_number(): void
    {
        $contact = Contact::query()->create([
            'company_name' => 'Futura',
            'company_id' => '1',
            'company_address' => '—',
        ]);

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->id,
            'invoice_number' => 'Tarp savo sąskaitų (Between account)',
            'invoice_date' => '2026-07-30',
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
            'upload_date' => '2026-07-30',
            'uploaded_by' => User::factory()->create()->id,
        ]);

        $this->assertTrue($invoice->applySuggestedCategoryIfMissing());
        $invoice->refresh();
        $this->assertTrue($invoice->is_own_account);
        $this->assertSame(Invoice::OWN_ACCOUNT_LABEL, $invoice->financeCategoryName());
        $this->assertSame(Invoice::OWN_ACCOUNT_FILTER, $invoice->storedFinanceTypeValue());
        $this->assertTrue($invoice->applyFinanceCategory(null));
        $invoice->refresh();
        $this->assertFalse((bool) $invoice->is_own_account);
        $this->assertNull($invoice->storedFinanceTypeValue());
    }
}
