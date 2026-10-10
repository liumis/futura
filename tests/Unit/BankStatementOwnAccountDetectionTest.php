<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Enums\CompanyBankAccountType;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use App\Models\CompanySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementOwnAccountDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_counterpart_iban_matching_company_account_is_own_transfer(): void
    {
        $this->seedCompanyAccounts();

        $transaction = $this->makeTransaction([
            'account_iban' => 'LT117044090110985504',
            'counterpart_iban' => 'LT357044090113587970',
            'counterpart_name' => 'Someone',
            'description' => 'Ordinary payment text',
        ]);

        $this->assertTrue($transaction->isInternalTransfer());
    }

    public function test_foreign_counterpart_iban_is_not_own_transfer(): void
    {
        $this->seedCompanyAccounts();

        $transaction = $this->makeTransaction([
            'account_iban' => 'LT117044090110985504',
            'counterpart_iban' => 'LT123000000000000001',
            'counterpart_name' => 'Supplier UAB',
            'description' => 'Invoice 100',
        ]);

        $this->assertFalse($transaction->isInternalTransfer());
    }

    public function test_diacritic_typo_tarp_savo_is_detected(): void
    {
        $this->seedCompanyAccounts();

        $transaction = $this->makeTransaction([
            'account_iban' => 'LT117044090110985504',
            'counterpart_iban' => null,
            'counterpart_name' => 'Tarp sąvo sąskaitų',
            'description' => '315 · Tarp sąvo sąskaitų',
        ]);

        $this->assertTrue($transaction->isInternalTransfer());
    }

    public function test_company_settings_iban_counts_as_own(): void
    {
        CompanySetting::instance()->update([
            'company_iban' => 'LT999999999999999999',
        ]);
        CompanyBankAccount::ownAccountIdentifierMap(fresh: true);

        $transaction = $this->makeTransaction([
            'account_iban' => 'LT117044090110985504',
            'counterpart_iban' => 'LT999999999999999999',
            'description' => 'Move',
        ]);

        $this->assertTrue($transaction->isInternalTransfer());
    }

    public function test_not_own_transfer_scope_keeps_external_income(): void
    {
        $this->seedCompanyAccounts();

        $income = $this->makeTransaction([
            'direction' => BankStatementDirection::Credit,
            'account_iban' => 'LT357044090113587970',
            'counterpart_name' => 'Customer UAB',
            'counterpart_iban' => 'LT123000000000000001',
            'description' => 'Customer income line',
        ]);
        $own = $this->makeTransaction([
            'direction' => BankStatementDirection::Debit,
            'account_iban' => 'LT117044090110985504',
            'counterpart_name' => 'Pavedimas',
            'counterpart_iban' => 'LT357044090113587970',
            'description' => 'Own transfer line',
        ]);

        $this->assertFalse($income->isInternalTransfer());
        $this->assertTrue($own->isInternalTransfer());

        $this->assertTrue(
            BankStatementTransaction::query()->whereKey($income->id)->notOwnTransfer()->exists(),
        );
        $this->assertTrue(
            BankStatementTransaction::query()->whereKey($own->id)->ownTransfer()->exists(),
        );
        $this->assertFalse(
            BankStatementTransaction::query()->whereKey($own->id)->notOwnTransfer()->exists(),
        );
        $this->assertTrue(
            BankStatementTransaction::query()
                ->where('direction', BankStatementDirection::Credit->value)
                ->notOwnTransfer()
                ->whereKey($income->id)
                ->exists(),
        );
    }

    private function seedCompanyAccounts(): void
    {
        CompanySetting::instance()->update([
            'company_iban' => 'LT117044090110985504',
            'company_name' => 'Futura Textiles Europe, UAB',
        ]);

        foreach ([
            ['iban' => 'LT117044090110985504', 'type' => CompanyBankAccountType::Expense, 'aliases' => ['ACC-27101-5504']],
            ['iban' => 'LT357044090113587970', 'type' => CompanyBankAccountType::Income, 'aliases' => ['ACC-27102-7970']],
            ['iban' => 'LT677044090113979371', 'type' => CompanyBankAccountType::CreditCard, 'aliases' => ['ACC-27103-9371']],
        ] as $row) {
            CompanyBankAccount::query()->updateOrCreate(
                ['iban' => $row['iban']],
                [
                    'statement_aliases' => $row['aliases'],
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(array $overrides): BankStatementTransaction
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'test.xml',
            'bank_name' => 'SEB',
            'account_iban' => 'LT117044090110985504',
            'account_currency' => 'EUR',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        return BankStatementTransaction::query()->create(array_merge([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => uniqid('fp_', true),
            'month_key' => '2026-06',
            'booking_date' => '2026-06-02',
            'direction' => BankStatementDirection::Debit,
            'amount' => 100,
            'currency' => 'EUR',
            'no_document_needed' => false,
        ], $overrides));
    }
}
