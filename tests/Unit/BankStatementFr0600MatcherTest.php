<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Document;
use App\Models\ExpenseType;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRate;
use App\Services\BankStatements\BankStatementDocumentMatcher;
use App\Services\Fr0600ReportDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BankStatementFr0600MatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00'));
        CompanySetting::query()->create([
            'company_name' => 'UAB FUTURA TEXTILES EUROPE',
            'company_id' => '306740042',
            'company_vat' => 'LT306740042',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_vmi_pvm_payment_suggests_matching_fr0600_document(): void
    {
        $this->makeInvoice('sales', 'PVM1', 21, 100, 21, '2026-07-10');
        $this->makeInvoice('purchases', 'PVM1', 21, 40, 8.40, '2026-07-20');
        // field 36 = 21 - 8.40 = 12.60

        $type = Fr0600ReportDocumentGenerator::ensureDocumentTypeExists();
        $document = Document::query()->create([
            'document_date' => '2026-07-31',
            'name' => 'FR0600 deklaracija — 2026 liepa',
            'document_type_id' => $type->getKey(),
            'file_path' => 'docs/fr0600-2026-07.pdf',
            'flag_approved' => true,
        ]);

        $transaction = $this->makeTransaction([
            'booking_date' => '2026-08-21',
            'amount' => 12.60,
            'counterpart_name' => 'VALSTYBINĖ MOKESČIŲ INSPEKCIJA PVM',
            'description' => '1001 PVM 2026-07',
            'counterpart_iban' => 'LT057044060007887175',
        ]);

        $this->assertTrue(BankStatementDocumentMatcher::isVmiPvmTransfer($transaction));
        $this->assertFalse(BankStatementDocumentMatcher::isVmiGpmTransfer($transaction));
        $this->assertSame(
            (int) $document->getKey(),
            (int) BankStatementDocumentMatcher::suggestionFr0600For($transaction)?->getKey(),
        );
    }

    public function test_pvm_payment_without_fr0600_document_is_still_detected(): void
    {
        $this->makeInvoice('sales', 'PVM1', 21, 100, 21, '2026-07-10');

        $transaction = $this->makeTransaction([
            'booking_date' => '2026-08-21',
            'amount' => 21.00,
            'counterpart_name' => 'VALSTYBINĖ MOKESČIŲ INSPEKCIJA PVM',
            'description' => 'PVM mokestis',
        ]);

        $this->assertTrue(BankStatementDocumentMatcher::isVmiPvmTransfer($transaction));
        $this->assertNull(BankStatementDocumentMatcher::suggestionFr0600For($transaction));
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeTransaction(array $attrs): BankStatementTransaction
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'test.xml',
            'stored_path' => 'bank-statements/test.xml',
            'file_hash' => hash('sha256', uniqid('fr0600', true)),
            'account_iban' => 'LT117044090110985504',
            'bank_name' => 'SEB',
            'imported_at' => now(),
        ]);

        return BankStatementTransaction::query()->create(array_merge([
            'bank_statement_import_id' => $import->getKey(),
            'booking_date' => '2026-08-01',
            'value_date' => '2026-08-01',
            'month_key' => '2026-08',
            'direction' => BankStatementDirection::Debit,
            'amount' => 10,
            'currency' => 'EUR',
            'account_iban' => 'LT117044090110985504',
            'bank_name' => 'SEB',
            'fingerprint' => hash('sha256', uniqid('tx', true)),
        ], $attrs));
    }

    private function makeInvoice(
        string $direction,
        string $classificator,
        float $rate,
        float $base,
        float $vat,
        string $date,
    ): Invoice {
        $user = User::factory()->create();
        $contact = Contact::query()->firstOrCreate(
            ['company_id' => '111'],
            ['company_name' => 'Partner UAB', 'company_address' => 'Vilnius'],
        );
        $income = IncomeType::query()->firstOrCreate(['name' => 'Sales']);
        $expense = ExpenseType::query()->firstOrCreate(['name' => 'Purchases']);
        $vatRate = VatRate::query()->firstOrCreate(
            ['classificator' => $classificator],
            ['rate' => $rate],
        );

        return Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'FR-'.$classificator.'-'.$date.'-'.uniqid(),
            'invoice_date' => $date,
            'upload_date' => $date,
            'uploaded_by' => $user->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => $base,
            'vat' => $vat,
            'sum_inc_vat' => round($base + $vat, 2),
            'vat_rate_id' => $vatRate->getKey(),
            'income_type_id' => $direction === 'sales' ? $income->getKey() : null,
            'expense_type_id' => $direction === 'purchases' ? $expense->getKey() : null,
        ]);
    }
}
