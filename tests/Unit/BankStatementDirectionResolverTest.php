<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Enums\CompanyBankAccountType;
use App\Models\CompanyBankAccount;
use App\Models\CompanySetting;
use App\Services\BankStatements\BankStatementDirectionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementDirectionResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
                    'sort_order' => 1,
                ],
            );
        }

        CompanyBankAccount::ownAccountIdentifierMap(fresh: true);
    }

    public function test_payment_to_external_iban_is_expense(): void
    {
        $direction = BankStatementDirectionResolver::resolve(
            BankStatementDirection::Debit,
            'LT117044090110985504',
            'LT123000000000000001',
            'Mayur Uniquoters LTD',
            'Invoice 287',
        );

        $this->assertSame(BankStatementDirection::Debit, $direction);
        $this->assertFalse(BankStatementDirectionResolver::isOwnTransfer(
            'LT117044090110985504',
            'LT123000000000000001',
        ));
    }

    public function test_receipt_from_external_iban_is_income(): void
    {
        $direction = BankStatementDirectionResolver::resolve(
            BankStatementDirection::Credit,
            'LT357044090113587970',
            'LT123000000000000001',
            'Autoleder KFT',
            'INVOICE NR.:0095',
        );

        $this->assertSame(BankStatementDirection::Credit, $direction);
    }

    public function test_transfer_between_company_accounts_is_own(): void
    {
        $this->assertTrue(BankStatementDirectionResolver::isOwnTransfer(
            'LT117044090110985504',
            'LT357044090113587970',
            'UAB FUTURA TEXTILES EUROPE',
            'Tarp savo sąskaitų',
        ));
    }

    public function test_generic_pavedimas_label_is_own_transfer(): void
    {
        $this->assertTrue(BankStatementDirectionResolver::isOwnTransfer(
            'LT117044090110985504',
            null,
            'Pervedimas',
            '301 · Pervedimas',
        ));
    }

    public function test_lesu_grazinimas_refund_between_own_accounts_is_own_transfer(): void
    {
        CompanyBankAccount::query()->where('iban', 'LT677044090113979371')->update([
            'name' => 'UAB FUTURA TEXTILES EUROPE Credit card',
        ]);
        CompanyBankAccount::query()->where('iban', 'LT357044090113587970')->update([
            'name' => 'UAB FUTURA TEXTILES EUROPE Income',
        ]);
        CompanyBankAccount::ownAccountIdentifierMap(fresh: true);

        $this->assertTrue(BankStatementDirectionResolver::isOwnTransfer(
            'LT357044090113587970',
            null,
            'Lėšų grąžinimas (Refund)',
            '324 · Lėšų grąžinimas (Refund) →',
        ));

        $this->assertTrue(BankStatementDirectionResolver::isOwnTransfer(
            'LT677044090113979371',
            null,
            'UAB FUTURA TEXTILES EUROPE Income',
            '324 · Lėšų grąžinimas (Refund) ←',
        ));
    }

    public function test_expense_account_outbound_without_iban_is_expense(): void
    {
        $direction = BankStatementDirectionResolver::resolve(
            BankStatementDirection::Debit,
            'LT117044090110985504',
            null,
            'Mayur Uniquoters LTD',
            '287',
        );

        $this->assertSame(BankStatementDirection::Debit, $direction);
    }
}
