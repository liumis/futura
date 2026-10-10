<?php

namespace Tests\Feature;

use App\Enums\BankStatementDirection;
use App\Enums\CompanyBankAccountType;
use App\Filament\Admin\Pages\BankStatements;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\ExpenseType;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankStatementsInvoiceLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-bank-invoices@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_unlinked_payment_shows_add_invoice_button(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Credit,
            'amount' => 150.50,
            'currency' => 'EUR',
            'counterpart_name' => 'Customer UAB',
            'description' => 'INV-1001',
        ]);

        $transactionId = (int) BankStatementTransaction::query()->value('id');

        $this->actingAs($this->admin);

        $this->get(BankStatements::getUrl())->assertOk();

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->assertSee('Add invoice')
            ->assertSee('Payment date')
            ->assertSee('Status')
            ->assertSee('Paid')
            ->assertSee('unlinked')
            ->assertSee('Customer UAB')
            ->call('mountAction', 'createInvoice', ['transactionId' => $transactionId])
            ->assertSet('mountedActions.0.name', 'createInvoice')
            ->assertSet('mountedActions.0.arguments.transactionId', $transactionId);
    }

    public function test_matching_invoice_is_shown_for_confirmation(): void
    {
        $contact = Contact::query()->create([
            'company_name' => 'Customer UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
        ]);
        Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-1001',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 150.50,
            'vat' => 0,
            'sum_inc_vat' => 150.50,
        ]);

        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);
        $transaction = BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Credit,
            'amount' => 150.50,
            'currency' => 'EUR',
            'counterpart_name' => 'Customer UAB',
            'description' => 'INV-1001',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->assertSee('INV-1001')
            ->assertSee('Confirm')
            ->assertSee('suggested')
            ->call('confirmSuggestedInvoice', $transaction->getKey())
            ->assertSee('Unlink');

        $this->assertSame(
            Invoice::query()->where('invoice_number', 'INV-1001')->value('id'),
            $transaction->fresh()->invoice_id,
        );
    }

    public function test_matching_contract_is_shown_for_confirmation(): void
    {
        $contact = Contact::query()->create([
            'company_name' => 'Office Rent UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
        ]);
        $payment = Payment::factory()->create([
            'name' => 'Office rent',
            'contact_id' => $contact->getKey(),
            'amount' => 450,
            'due_date' => '2026-08-01',
            'paid' => false,
        ]);

        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);
        $transaction = BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Debit,
            'amount' => 450,
            'currency' => 'EUR',
            'counterpart_name' => 'Office Rent UAB',
            'description' => 'Office rent',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->assertSee('Office rent')
            ->assertSee('Confirm')
            ->assertSee('suggested')
            ->call('confirmSuggestedPayment', $transaction->getKey())
            ->assertSee('Unlink');

        $this->assertSame($payment->getKey(), $transaction->fresh()->payment_id);
        $this->assertTrue((bool) $payment->fresh()->paid);
    }

    public function test_link_existing_action_mounts(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);
        $transaction = BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Credit,
            'amount' => 12,
            'currency' => 'EUR',
            'counterpart_name' => 'No Match UAB',
            'description' => 'Payment',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->assertSee('Add invoice')
            ->call('mountAction', 'linkExistingInvoice', ['transactionId' => $transaction->getKey()])
            ->assertSet('mountedActions.0.name', 'linkExistingInvoice');
    }

    public function test_link_existing_contract_action_mounts(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);
        $transaction = BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Debit,
            'amount' => 12,
            'currency' => 'EUR',
            'counterpart_name' => 'No Match UAB',
            'description' => 'Payment',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->call('mountAction', 'linkExistingPayment', ['transactionId' => $transaction->getKey()])
            ->assertSet('mountedActions.0.name', 'linkExistingPayment');
    }

    public function test_filters_by_invoice_status_counterpart_and_description(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 2,
            'imported_count' => 2,
            'skipped_count' => 0,
        ]);

        $contact = Contact::query()->create([
            'company_name' => 'Linked Co',
            'company_id' => '555',
            'company_address' => 'Vilnius',
        ]);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-LINKED',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Credit,
            'amount' => 10,
            'currency' => 'EUR',
            'counterpart_name' => 'Linked Co',
            'description' => 'Salary August',
            'invoice_id' => $invoice->getKey(),
        ]);
        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-04',
            'direction' => BankStatementDirection::Debit,
            'amount' => 40,
            'currency' => 'EUR',
            'counterpart_name' => 'Office Rent UAB',
            'description' => 'Office rent invoice 88',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->assertSee('Salary August')
            ->assertSee('Office rent invoice 88')
            ->set('filters.link_status', 'missing')
            ->assertDontSee('Salary August')
            ->assertSee('Office rent invoice 88')
            ->set('filters.link_status', 'linked')
            ->assertSee('Salary August')
            ->assertDontSee('Office rent invoice 88')
            ->set('filters.link_status', null)
            ->set('filters.counterpart', 'Office Rent UAB')
            ->assertSee('Office rent invoice 88')
            ->assertDontSee('Salary August')
            ->set('filters.counterpart', null)
            ->set('filters.description', 'Salary')
            ->assertSee('Salary August')
            ->assertDontSee('Office rent invoice 88')
            ->set('filters.description', 'no-such-payment')
            ->assertSee('No payments match the filters.');
    }

    public function test_own_account_and_fee_lines_autolink_to_bank_statement(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'seb-2026-08.xml',
            'sharepoint_web_url' => 'https://sharepoint.example/bank-statements/seb-2026-08.xml',
            'sharepoint_path' => 'SS Documents/bank-statements/2026/2026-08/seb-2026-08.xml',
            'transaction_count' => 2,
            'imported_count' => 2,
            'skipped_count' => 0,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Debit,
            'amount' => 70,
            'currency' => 'EUR',
            'counterpart_name' => 'Tarp savo sąskaitų (Between account)',
            'description' => 'Own transfer autolink',
            'no_document_needed' => true,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-04',
            'direction' => BankStatementDirection::Debit,
            'amount' => 2.5,
            'currency' => 'EUR',
            'counterpart_name' => 'Kortelės naudojimo mokestis',
            'description' => 'Card fee autolink',
            'no_document_needed' => true,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-05',
            'direction' => BankStatementDirection::Debit,
            'amount' => 40,
            'currency' => 'EUR',
            'counterpart_name' => 'Supplier UAB',
            'description' => 'Needs invoice still',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->assertSee('seb-2026-08.xml')
            ->assertSee('Own transfer autolink')
            ->assertSee('Card fee autolink')
            ->assertSee('Needs invoice still')
            ->assertSee('Bank statement')
            ->assertSee('Own accounts')
            ->assertSeeHtml('href="https://sharepoint.example/bank-statements/seb-2026-08.xml"')
            ->assertDontSeeHtml('bank-statement-imports/')
            ->set('filters.link_status', 'linked')
            ->assertSee('Own transfer autolink')
            ->assertSee('Card fee autolink')
            ->assertDontSee('Needs invoice still')
            ->set('filters.link_status', 'missing')
            ->assertSee('Needs invoice still')
            ->assertDontSee('Own transfer autolink')
            ->assertDontSee('Card fee autolink');
    }

    public function test_filters_by_payment_status(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 3,
            'imported_count' => 3,
            'skipped_count' => 0,
        ]);

        $contact = Contact::query()->create([
            'company_name' => 'Status Co',
            'company_id' => '777',
            'company_address' => 'Vilnius',
        ]);

        $paidInvoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PAID',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
            'paid_amount' => 100,
            'paid' => true,
        ]);
        $partlyInvoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-PARTLY',
            'invoice_date' => '2026-08-02',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
            'paid_amount' => 40,
            'paid' => false,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Credit,
            'amount' => 100,
            'currency' => 'EUR',
            'counterpart_name' => 'Status Co',
            'description' => 'Fully paid line',
            'invoice_id' => $paidInvoice->getKey(),
        ]);
        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-04',
            'direction' => BankStatementDirection::Credit,
            'amount' => 40,
            'currency' => 'EUR',
            'counterpart_name' => 'Status Co',
            'description' => 'Partly paid line',
            'invoice_id' => $partlyInvoice->getKey(),
        ]);
        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-05',
            'direction' => BankStatementDirection::Debit,
            'amount' => 15,
            'currency' => 'EUR',
            'counterpart_name' => 'Open Supplier',
            'description' => 'Unlinked bank-paid line',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->assertSee('Fully paid line')
            ->assertSee('Partly paid line')
            ->assertSee('Unlinked bank-paid line')
            ->set('filters.payment_status', 'paid')
            ->assertSee('Fully paid line')
            ->assertSee('Unlinked bank-paid line')
            ->assertDontSee('Partly paid line')
            ->set('filters.payment_status', 'partly_paid')
            ->assertSee('Partly paid line')
            ->assertDontSee('Fully paid line')
            ->assertDontSee('Unlinked bank-paid line')
            ->set('filters.payment_status', 'unpaid')
            ->assertDontSee('Unlinked bank-paid line')
            ->assertDontSee('Fully paid line')
            ->assertDontSee('Partly paid line');
    }

    public function test_bank_account_filter_limits_visible_transactions(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'multi-account.xml',
            'transaction_count' => 2,
            'imported_count' => 2,
            'skipped_count' => 0,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-01',
            'direction' => BankStatementDirection::Credit,
            'amount' => 10,
            'currency' => 'EUR',
            'bank_name' => 'SEB',
            'account_iban' => 'LT121000011101001000',
            'counterpart_name' => 'Seb Counterpart',
            'description' => 'SEB line',
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-02',
            'direction' => BankStatementDirection::Credit,
            'amount' => 20,
            'currency' => 'EUR',
            'bank_name' => 'Swedbank',
            'account_iban' => 'LT767300010000000001',
            'counterpart_name' => 'Swed Counterpart',
            'description' => 'Swedbank line',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->assertSee('Bank account')
            ->assertSee('From')
            ->assertSee('To')
            ->assertSee('SEB line')
            ->assertSee('Swedbank line')
            ->set('filters.bank_account', 'LT121000011101001000')
            ->assertSee('SEB line')
            ->assertDontSee('Swedbank line');
    }

    public function test_direction_filter_separates_own_account_transfers(): void
    {
        $this->seedCompanyAccounts();

        $import = BankStatementImport::query()->create([
            'original_filename' => 'own-filter.xml',
            'transaction_count' => 3,
            'imported_count' => 3,
            'skipped_count' => 0,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-01',
            'direction' => BankStatementDirection::Credit,
            'amount' => 100,
            'currency' => 'EUR',
            'account_iban' => 'LT357044090113587970',
            'counterpart_name' => 'Customer UAB',
            'counterpart_iban' => 'LT123000000000000001',
            'description' => 'Customer income line',
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-02',
            'direction' => BankStatementDirection::Debit,
            'amount' => 40,
            'currency' => 'EUR',
            'account_iban' => 'LT117044090110985504',
            'counterpart_name' => 'Supplier UAB',
            'counterpart_iban' => 'LT123000000000000002',
            'description' => 'Supplier outcome line',
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Debit,
            'amount' => 70,
            'currency' => 'EUR',
            'account_iban' => 'LT117044090110985504',
            'counterpart_name' => 'Pavedimas',
            'counterpart_iban' => 'LT357044090113587970',
            'description' => 'Own transfer line',
            'no_document_needed' => true,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->assertSee('Between own accounts')
            ->assertSee('Customer income line')
            ->assertSee('Supplier outcome line')
            ->assertSee('Own transfer line')
            ->set('filters.direction', BankStatementDirection::Credit->value)
            ->assertSee('Customer income line')
            ->assertDontSee('Supplier outcome line')
            ->assertDontSee('Own transfer line')
            ->set('filters.direction', BankStatementDirection::Debit->value)
            ->assertSee('Supplier outcome line')
            ->assertDontSee('Customer income line')
            ->assertDontSee('Own transfer line')
            ->set('filters.direction', 'own')
            ->assertSee('Own transfer line')
            ->assertDontSee('Customer income line')
            ->assertDontSee('Supplier outcome line');
    }

    public function test_inline_category_saves_on_the_linked_invoice(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);
        $contact = Contact::query()->create([
            'company_name' => 'Category Co',
            'company_id' => '888',
            'company_address' => 'Vilnius',
        ]);
        $income = IncomeType::query()->create(['name' => 'Prekės']);
        $expense = ExpenseType::query()->create(['name' => 'Nuoma']);
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->getKey(),
            'invoice_number' => 'INV-CAT-BS',
            'invoice_date' => '2026-08-01',
            'upload_date' => '2026-08-24',
            'uploaded_by' => $this->admin->getKey(),
            'pdf_path' => '',
            'sum_without_vat' => 40,
            'vat' => 0,
            'sum_inc_vat' => 40,
        ]);
        $transaction = BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Debit,
            'amount' => 40,
            'currency' => 'EUR',
            'counterpart_name' => 'Category Co',
            'description' => 'Office rent',
            'invoice_id' => $invoice->getKey(),
        ]);

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->assertSee('Category')
            ->assertSeeHtml('wire:change="updateFinanceCategory('.$invoice->getKey())
            ->assertSee('Missing type')
            ->call('updateFinanceCategory', $invoice->getKey(), 'expense:'.$expense->getKey());

        $invoice->refresh();
        $this->assertSame($expense->getKey(), $invoice->expense_type_id);
        $this->assertNull($invoice->income_type_id);
        $this->assertFalse((bool) $invoice->is_own_account);

        Livewire::test(BankStatements::class)
            ->set('filters.month', 'any')
            ->call('updateFinanceCategory', $invoice->getKey(), 'income:'.$income->getKey());

        $invoice->refresh();
        $this->assertSame($income->getKey(), $invoice->income_type_id);
        $this->assertNull($invoice->expense_type_id);

        $transaction->refresh();
        $this->assertSame($invoice->getKey(), $transaction->invoice_id);
        $this->assertFalse(Schema::hasColumn('bank_statement_transactions', 'income_type_id'));
        $this->assertFalse(Schema::hasColumn('bank_statement_transactions', 'expense_type_id'));
        $this->assertFalse(Schema::hasColumn('bank_statement_transactions', 'is_own_account'));
    }

    private function seedCompanyAccounts(): void
    {
        CompanySetting::instance()->update([
            'company_iban' => 'LT117044090110985504',
        ]);

        foreach ([
            ['iban' => 'LT117044090110985504', 'type' => CompanyBankAccountType::Expense],
            ['iban' => 'LT357044090113587970', 'type' => CompanyBankAccountType::Income],
        ] as $row) {
            CompanyBankAccount::query()->updateOrCreate(
                ['iban' => $row['iban']],
                [
                    'bank_name' => 'SEB',
                    'type' => $row['type'],
                    'is_active' => true,
                    'is_primary' => false,
                    'sort_order' => 1,
                ],
            );
        }

        CompanyBankAccount::ownAccountIdentifierMap(fresh: true);
    }
}
