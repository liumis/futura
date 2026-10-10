<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementRefundSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_outgoing_refund_status(): void
    {
        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Debit,
            'counterpart_name' => 'TENA TEX TRADE , s.r.o.',
            'description' => 'VM325 · KAR13136 2196 refund',
            'amount' => 2196,
        ]);

        $this->assertTrue($transaction->isRefund());
        $summary = $transaction->settlementSummary();
        $this->assertSame('refund', $summary['status']);
        $this->assertSame('Refund', $summary['label']);
    }

    public function test_incoming_refunded_status(): void
    {
        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Credit,
            'counterpart_name' => 'Air Baltic Corporation A/S',
            'description' => 'FT26176XY40MIBFT3503 · REFUND 2622153364 8NAMDX',
            'amount' => 153.98,
        ]);

        $this->assertTrue($transaction->isRefund());
        $summary = $transaction->settlementSummary();
        $this->assertSame('refunded', $summary['status']);
        $this->assertSame('Refunded', $summary['label']);
    }

    public function test_lithuanian_grazinimas_detected(): void
    {
        $transaction = $this->makeTransaction([
            'direction' => BankStatementDirection::Credit,
            'counterpart_name' => 'pav (refund)',
            'description' => '1782549958019 · pav (refund)',
            'amount' => 49.90,
        ]);

        $this->assertTrue($transaction->isRefund());
        $this->assertSame('refunded', $transaction->settlementSummary()['status']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(array $overrides = []): BankStatementTransaction
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'test.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        return BankStatementTransaction::query()->create(array_merge([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => bin2hex(random_bytes(16)),
            'month_key' => '2026-06',
            'booking_date' => '2026-06-29',
            'direction' => BankStatementDirection::Debit,
            'amount' => 100,
            'currency' => 'EUR',
            'counterpart_name' => 'Test',
            'description' => 'Test',
        ], $overrides));
    }
}
