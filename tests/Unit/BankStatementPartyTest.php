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

class BankStatementPartyTest extends TestCase
{
    use RefreshDatabase;

    public function test_outcome_flows_from_own_account_to_counterpart(): void
    {
        $this->seedCompanyAccounts();

        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Debit,
            'account_iban' => 'LT117044090110985504',
            'bank_name' => 'SEB',
            'counterpart_name' => 'Supplier UAB',
            'counterpart_iban' => 'LT123000000000000001',
        ]);

        $from = $transaction->fromParty();
        $to = $transaction->toParty();

        $this->assertFalse($from['is_counterpart']);
        $this->assertSame('SEB · Expense', $from['name']);
        $this->assertSame('LT117044090110985504', $from['iban']);

        $this->assertTrue($to['is_counterpart']);
        $this->assertSame('Supplier, UAB', $to['name']);
        $this->assertSame('LT123000000000000001', $to['iban']);
        $this->assertSame('SEB · Expense · LT117044090110985504', $transaction->partyLine($from));
    }

    public function test_income_flows_from_counterpart_to_own_account(): void
    {
        $this->seedCompanyAccounts();

        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Credit,
            'account_iban' => 'LT357044090113587970',
            'bank_name' => 'SEB',
            'counterpart_name' => 'Customer UAB',
            'counterpart_iban' => 'LT123000000000000001',
        ]);

        $from = $transaction->fromParty();
        $to = $transaction->toParty();

        $this->assertTrue($from['is_counterpart']);
        $this->assertSame('Customer, UAB', $from['name']);
        $this->assertSame('LT123000000000000001', $from['iban']);

        $this->assertFalse($to['is_counterpart']);
        $this->assertSame('SEB · Income', $to['name']);
        $this->assertSame('LT357044090113587970', $to['iban']);
    }

    public function test_generic_own_transfer_label_uses_company_account_name(): void
    {
        $this->seedCompanyAccounts();

        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Debit,
            'account_iban' => 'LT117044090110985504',
            'counterpart_name' => 'Pavedimas',
            'counterpart_iban' => 'LT357044090113587970',
        ]);

        $to = $transaction->toParty();

        $this->assertSame('SEB · Income', $to['name']);
        $this->assertSame('LT357044090113587970', $to['iban']);
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
