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

class BankStatementOwnAccountRefundPartyTest extends TestCase
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
            [
                'iban' => 'LT357044090113587970',
                'type' => CompanyBankAccountType::Income,
                'name' => 'UAB FUTURA TEXTILES EUROPE Income',
                'aliases' => ['ACC-27102-7970'],
            ],
            [
                'iban' => 'LT677044090113979371',
                'type' => CompanyBankAccountType::CreditCard,
                'name' => 'UAB FUTURA TEXTILES EUROPE Credit card',
                'aliases' => ['ACC-27103-9371'],
            ],
        ] as $row) {
            CompanyBankAccount::query()->updateOrCreate(
                ['iban' => $row['iban']],
                [
                    'statement_aliases' => $row['aliases'],
                    'bank_name' => 'SEB',
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'is_active' => true,
                    'sort_order' => 1,
                ],
            );
        }

        CompanyBankAccount::ownAccountIdentifierMap(fresh: true);
    }

    public function test_from_and_to_use_seb_account_labels_for_lesu_grazinimas_pair(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'test.xls',
            'transaction_count' => 2,
            'imported_count' => 2,
            'skipped_count' => 0,
        ]);

        $outbound = BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-06',
            'booking_date' => '2026-06-29',
            'direction' => BankStatementDirection::Debit,
            'amount' => 49.90,
            'currency' => 'EUR',
            'account_iban' => 'LT357044090113587970',
            'bank_name' => 'SEB',
            'counterpart_name' => 'UAB FUTURA TEXTILES EUROPE Credit card',
            'description' => '324 · Lėšų grąžinimas (Refund) →',
            'end_to_end_id' => '324',
        ]);

        $inbound = BankStatementTransaction::query()->create([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-06',
            'booking_date' => '2026-06-29',
            'direction' => BankStatementDirection::Credit,
            'amount' => 49.90,
            'currency' => 'EUR',
            'account_iban' => 'LT677044090113979371',
            'bank_name' => 'SEB',
            'counterpart_name' => 'UAB FUTURA TEXTILES EUROPE Income',
            'description' => '324 · Lėšų grąžinimas (Refund) ←',
            'end_to_end_id' => '324',
        ]);

        $this->assertTrue($outbound->isInternalTransfer());
        $this->assertFalse($outbound->isRefund());
        $this->assertSame('SEB · Income', $outbound->fromParty()['name']);
        $this->assertSame('LT357044090113587970', $outbound->fromParty()['iban']);
        $this->assertSame('SEB · Credit card', $outbound->toParty()['name']);
        $this->assertSame('LT677044090113979371', $outbound->toParty()['iban']);

        $this->assertTrue($inbound->isInternalTransfer());
        $this->assertFalse($inbound->isRefund());
        $this->assertSame('SEB · Income', $inbound->fromParty()['name']);
        $this->assertSame('LT357044090113587970', $inbound->fromParty()['iban']);
        $this->assertSame('SEB · Credit card', $inbound->toParty()['name']);
        $this->assertSame('LT677044090113979371', $inbound->toParty()['iban']);
        $this->assertSame('Own accounts', $inbound->settlementSummary()['label']);
    }
}
