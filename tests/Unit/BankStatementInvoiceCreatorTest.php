<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BankStatements\BankStatementInvoiceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BankStatementInvoiceCreatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefill_uses_booking_date_amount_and_matched_contact(): void
    {
        $contact = Contact::query()->create([
            'company_name' => 'Customer UAB',
            'company_id' => '123456789',
            'company_vat' => 'LT123',
            'company_address' => 'Vilnius',
            'company_email' => 'billing@customer.test',
        ]);

        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Credit,
            'amount' => 150.50,
            'booking_date' => '2026-08-03',
            'counterpart_name' => 'Customer UAB',
            'description' => 'INV-1001',
        ]);

        $prefill = BankStatementInvoiceCreator::prefill($transaction);

        $this->assertTrue($prefill['is_income']);
        $this->assertSame($contact->getKey(), $prefill['contact_id']);
        $this->assertSame(0, $prefill['create_contact_if_missing']);
        $this->assertSame('2026-08-03', $prefill['invoice_date']);
        $this->assertSame(150.5, $prefill['sum_without_vat']);
        $this->assertSame(0, $prefill['vat']);
        $this->assertSame(150.5, $prefill['sum_inc_vat']);
        $this->assertSame('INV-1001', $prefill['invoice_number']);
        $this->assertSame('LT123', $prefill['provider_company_vat']);
        $this->assertSame('EUR', $prefill['currency']);
    }

    public function test_prefill_uses_transaction_currency(): void
    {
        $transaction = $this->makeTransaction([
            'currency' => 'USD',
            'amount' => 25,
        ]);

        $prefill = BankStatementInvoiceCreator::prefill($transaction);

        $this->assertSame('USD', $prefill['currency']);
        $this->assertSame(25.0, $prefill['sum_inc_vat']);
    }

    public function test_prefill_marks_debits_as_expense(): void
    {
        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Debit,
            'amount' => 40,
            'counterpart_name' => 'Unknown supplier',
            'description' => 'A very long payment description that should not become an invoice number because it exceeds forty characters',
        ]);

        $prefill = BankStatementInvoiceCreator::prefill($transaction);

        $this->assertFalse($prefill['is_income']);
        $this->assertNull($prefill['contact_id']);
        $this->assertSame(1, $prefill['create_contact_if_missing']);
        $this->assertSame('Unknown supplier', $prefill['provider_company_name']);
        $this->assertNull($prefill['invoice_number']);
        $this->assertSame(40.0, $prefill['sum_inc_vat']);
    }

    public function test_match_contact_prefers_exact_company_name(): void
    {
        Contact::query()->create([
            'company_name' => 'Customer Baltic UAB',
            'company_id' => '111',
            'company_address' => 'Kaunas',
        ]);
        $exact = Contact::query()->create([
            'company_name' => 'Customer',
            'company_id' => '222',
            'company_address' => 'Vilnius',
        ]);

        $matched = BankStatementInvoiceCreator::matchContact('Customer');

        $this->assertNotNull($matched);
        $this->assertSame($exact->getKey(), $matched->getKey());
    }

    public function test_create_requires_sharepoint(): void
    {
        $transaction = $this->makeTransaction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SharePoint must be enabled');

        app(BankStatementInvoiceCreator::class)->create($transaction, [
            'pdf_path' => 'invoices/missing.pdf',
            'invoice_date' => '2026-08-03',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
            'is_income' => true,
            'create_contact_if_missing' => 1,
            'provider_company_name' => 'New Co',
        ], 1);
    }

    public function test_create_rejects_already_linked_payment(): void
    {
        $user = User::factory()->create();
        $contact = Contact::query()->create([
            'company_name' => 'Linked Co',
            'company_id' => '333',
            'company_address' => 'Klaipeda',
        ]);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_date' => '2026-08-03',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ]);

        $transaction = $this->makeTransaction([
            'invoice_id' => $invoice->getKey(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already linked');

        app(BankStatementInvoiceCreator::class)->create($transaction, [
            'pdf_path' => 'invoices/file.pdf',
            'invoice_date' => '2026-08-03',
        ], (int) $user->getKey());
    }

    public function test_create_rejects_duplicate_invoice_number(): void
    {
        $user = User::factory()->create();
        $contact = Contact::query()->create([
            'company_name' => 'DSV Road UAB',
            'company_id' => '111552645',
            'company_address' => 'Vilnius',
        ]);
        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => '1025402',
            'invoice_date' => '2026-08-31',
            'upload_date' => '2026-09-16',
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 1439.84,
            'vat' => 302.37,
            'sum_inc_vat' => 1742.21,
        ]);

        $transaction = $this->makeTransaction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('1025402 already exists');

        app(BankStatementInvoiceCreator::class)->create($transaction, [
            'pdf_path' => 'invoices/file.pdf',
            'invoice_number' => '1025402',
            'invoice_date' => '2026-08-31',
            'contact_id' => $contact->getKey(),
        ], (int) $user->getKey());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(array $overrides = []): BankStatementTransaction
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        return BankStatementTransaction::query()->create(array_merge([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Credit,
            'amount' => 10,
            'currency' => 'EUR',
            'counterpart_name' => 'Customer UAB',
            'description' => 'Payment',
        ], $overrides));
    }
}
