<?php

namespace Tests\Feature;

use App\Enums\BankStatementDirection;
use App\Filament\Admin\Pages\Dashboard;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceCode;
use App\Models\InvoiceFinanceLine;
use App\Models\User;
use App\Services\UnbalancedFinanceMonths;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnbalancedFinanceMonthsTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_unbalanced_invoice_journal_months(): void
    {
        $uploader = User::factory()->create();
        $contact = Contact::query()->create([
            'company_name' => 'Balance Co',
            'company_id' => 'BAL-1',
            'company_address' => 'Test',
        ]);
        $code = InvoiceCode::query()->firstOrCreate(
            ['code' => '6000'],
            ['name' => 'Expense'],
        );

        $invoice = Invoice::query()->create([
            'contact_id' => $contact->id,
            'invoice_number' => 'UNBAL-1',
            'invoice_date' => '2026-09-15',
            'sum_without_vat' => 100,
            'vat' => 0,
            'sum_inc_vat' => 100,
            'currency' => 'EUR',
            'paid_amount' => 0,
            'paid' => false,
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $uploader->id,
        ]);

        InvoiceFinanceLine::query()->create([
            'invoice_id' => $invoice->id,
            'invoice_code_id' => $code->id,
            'name' => 'Debit only',
            'debit' => 100,
            'credit' => 0,
            'sort_order' => 1,
        ]);

        $months = UnbalancedFinanceMonths::journalMonths();

        $this->assertTrue($months->contains(fn (array $row): bool => $row['month_key'] === '2026-09'));
        $sep = $months->firstWhere('month_key', '2026-09');
        $this->assertSame('journal', $sep['kind']);
        $this->assertEqualsWithDelta(100.0, $sep['debit'], 0.001);
        $this->assertEqualsWithDelta(0.0, $sep['credit'], 0.001);
    }

    public function test_detects_unbalanced_own_account_transfer_months(): void
    {
        $ibanA = 'LT121000011101001000';
        $ibanB = 'LT121000011101001001';

        CompanyBankAccount::query()->create([
            'bank_name' => 'SEB A',
            'iban' => $ibanA,
            'is_active' => true,
        ]);
        CompanyBankAccount::query()->create([
            'bank_name' => 'SEB B',
            'iban' => $ibanB,
            'is_active' => true,
        ]);

        $import = BankStatementImport::query()->create([
            'original_filename' => 'test.xml',
            'content_hash' => 'hash-own-1',
            'uploaded_by' => User::factory()->create()->id,
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->id,
            'fingerprint' => 'own-cr-1',
            'month_key' => '2026-09',
            'booking_date' => '2026-09-10',
            'direction' => BankStatementDirection::Credit,
            'amount' => 500,
            'currency' => 'EUR',
            'account_iban' => $ibanA,
            'counterpart_iban' => $ibanB,
            'counterpart_name' => 'Futura Textiles Europe, UAB',
            'description' => 'Tarp savo saskaitu (Between account)',
        ]);

        // Missing matching debit leg → month unbalanced.
        $months = UnbalancedFinanceMonths::ownAccountTransferMonths();

        $this->assertTrue($months->contains(fn (array $row): bool => $row['month_key'] === '2026-09'));
        $sep = $months->firstWhere('month_key', '2026-09');
        $this->assertSame('own_account', $sep['kind']);
        $this->assertEqualsWithDelta(500.0, $sep['credit'], 0.001);
    }

    public function test_dashboard_shows_unbalanced_month_banner(): void
    {
        $this->actingAsAdmin();

        $contact = Contact::query()->create([
            'company_name' => 'Dash Balance Co',
            'company_id' => 'BAL-2',
            'company_address' => 'Test',
        ]);
        $code = InvoiceCode::query()->firstOrCreate(
            ['code' => '6000'],
            ['name' => 'Expense'],
        );
        $invoice = Invoice::query()->create([
            'contact_id' => $contact->id,
            'invoice_number' => 'UNBAL-DASH',
            'invoice_date' => '2026-08-01',
            'sum_without_vat' => 50,
            'vat' => 0,
            'sum_inc_vat' => 50,
            'currency' => 'EUR',
            'paid_amount' => 0,
            'paid' => false,
            'upload_date' => now()->toDateString(),
            'uploaded_by' => auth()->id(),
        ]);
        InvoiceFinanceLine::query()->create([
            'invoice_id' => $invoice->id,
            'invoice_code_id' => $code->id,
            'name' => 'Debit only',
            'debit' => 50,
            'credit' => 0,
            'sort_order' => 1,
        ]);

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee('Unbalanced months')
            ->assertSee('August 2026');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate(User::ROLE_ADMIN, 'web');
        $user = User::factory()->create(['email' => 'admin-balance-dash@example.com']);
        $user->assignRole(User::ROLE_ADMIN);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);

        return $user;
    }
}
