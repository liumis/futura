<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDisplayAmountKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_type_outgoing_bank_link_shows_as_expense_sign(): void
    {
        $invoice = $this->makeInvoice();
        $this->linkBank($invoice, BankStatementDirection::Debit);

        $this->assertNull($invoice->financeCategoryKind());
        $this->assertSame('expense', $invoice->fresh()->displayAmountKind());
    }

    public function test_missing_type_incoming_bank_link_shows_as_income_sign(): void
    {
        $invoice = $this->makeInvoice();
        $this->linkBank($invoice, BankStatementDirection::Credit);

        $this->assertNull($invoice->financeCategoryKind());
        $this->assertSame('income', $invoice->fresh()->displayAmountKind());
    }

    public function test_missing_type_without_bank_link_defaults_to_expense_sign(): void
    {
        $invoice = $this->makeInvoice();

        $this->assertSame('expense', $invoice->displayAmountKind());
    }

    public function test_income_category_keeps_plus_even_without_bank_link(): void
    {
        $invoice = $this->makeInvoice();
        $income = \App\Models\IncomeType::query()->create(['name' => 'Prekės']);
        $invoice->forceFill(['income_type_id' => $income->id])->save();

        $this->assertSame('income', $invoice->fresh()->displayAmountKind());
    }

    public function test_own_account_has_no_sign(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['is_own_account' => true])->save();

        $this->assertNull($invoice->fresh()->displayAmountKind());
    }

    private function makeInvoice(): Invoice
    {
        $contact = Contact::query()->create([
            'company_name' => 'Supplier',
            'company_id' => '1',
            'company_address' => '—',
        ]);
        $user = User::factory()->create();

        return Invoice::query()->create([
            'contact_id' => $contact->id,
            'invoice_number' => 'TEST-1',
            'invoice_date' => '2026-09-01',
            'sum_without_vat' => 100,
            'vat' => 21,
            'sum_inc_vat' => 121,
            'upload_date' => '2026-09-01',
            'uploaded_by' => $user->id,
        ]);
    }

    private function linkBank(Invoice $invoice, BankStatementDirection $direction): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'seb.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-09',
            'booking_date' => '2026-09-01',
            'direction' => $direction,
            'amount' => 121,
            'currency' => 'EUR',
            'counterpart_name' => 'Supplier',
            'invoice_id' => $invoice->getKey(),
        ]);
    }
}
