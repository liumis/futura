<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Services\BankStatements\BankStatementDirectionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankStatementFeeDetectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function feeExamples(): array
    {
        return [
            'card usage fee' => ['Kortelės naudojimo mokestis', null],
            'kasdienis plan' => ['KASDIENIS+ paslaugų planas', null],
            'mokestis rmo' => [null, 'Mokestis RMO26876332 (bank fee)'],
            'plain mokestis description' => [null, 'Mokestis'],
            'plain mokestis counterpart' => ['Mokestis', '228 · Mokestis'],
            'group transfer fee' => [null, 'Už grupinį pinigų pervedimą'],
            'group transfer fee ocr' => [null, 'Už grupini pinigy pervedima i kita banką'],
            'plain bank fee' => ['Mokestis (bank fee)', null],
            'bank services' => ['Banko paslaugos', '- · Banko paslaugos'],
        ];
    }

    #[DataProvider('feeExamples')]
    public function test_known_bank_fees_are_detected(?string $counterpart, ?string $description): void
    {
        $this->assertTrue(
            BankStatementDirectionResolver::isBankServiceFee($counterpart, $description),
        );

        $transaction = $this->makeTransaction([
            'counterpart_name' => $counterpart,
            'description' => $description,
        ]);

        $this->assertTrue($transaction->isBankServiceFee());
        $this->assertTrue($transaction->doesNotNeedDocument());
        $this->assertSame('Bank fee', $transaction->statementDocumentLabel());
        $this->assertSame(40.0, $transaction->outcomeAmount());
    }

    public function test_own_account_transfer_is_not_a_bank_fee(): void
    {
        $this->assertFalse(BankStatementDirectionResolver::isBankServiceFee(
            'Tarp savo sąskaitų (Between account)',
            '341 · Tarp savo sąskaitų (Between accounts)',
        ));

        $transaction = $this->makeTransaction([
            'counterpart_name' => 'Tarp savo sąskaitų (Between account)',
            'description' => '341 · Tarp savo sąskaitų (Between accounts)',
        ]);

        $this->assertTrue($transaction->isInternalTransfer());
        $this->assertFalse($transaction->isBankServiceFee());
        $this->assertTrue($transaction->doesNotNeedDocument());
        $this->assertSame('Own accounts', $transaction->statementDocumentLabel());
        $this->assertSame(0.0, $transaction->outcomeAmount());
    }

    public function test_supplier_invoice_is_not_a_bank_fee(): void
    {
        $this->assertFalse(BankStatementDirectionResolver::isBankServiceFee(
            'Supplier UAB',
            'Invoice FTE-0112',
        ));
    }

    public function test_sodra_and_salary_lines_are_not_bank_fees(): void
    {
        $this->assertFalse(BankStatementDirectionResolver::isBankServiceFee('SODRA 252', '332 · SODRA 252'));
        $this->assertFalse(BankStatementDirectionResolver::isBankServiceFee(null, 'Atlyginimas už 06 mėn. (salary)'));
    }

    public function test_statement_file_is_the_autolinked_document(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank-july.xml',
            'sharepoint_web_url' => 'https://sharepoint.example/swedbank-july.xml',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        $transaction = $this->makeTransaction([
            'bank_statement_import_id' => $import->getKey(),
            'counterpart_name' => 'Mokestis (bank fee)',
            'description' => 'Bank fee autolink',
        ]);
        $transaction->setRelation('import', $import);

        $this->assertTrue($transaction->isLinkedToStatement());
        $html = $import->openDocumentLinksHtml();
        $this->assertStringContainsString('swedbank-july.xml', $html);
        $this->assertStringContainsString('https://sharepoint.example/swedbank-july.xml', $html);
        $this->assertStringNotContainsString('localhost', $html);
        $this->assertStringNotContainsString('127.0.0.1', $html);
        $this->assertStringNotContainsString('bank-statement-imports/', $html);
    }

    public function test_local_file_route_is_not_used_as_document_link(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank-july.xml',
            'sharepoint_web_url' => 'http://localhost:8001/bank-statement-imports/9/file',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        $this->assertNull($import->primarySharepointUrl());
        $this->assertSame('swedbank-july.xml', $import->openDocumentLinksHtml());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(array $overrides): BankStatementTransaction
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'fees.xml',
            'bank_name' => 'SEB',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
        ]);

        return BankStatementTransaction::query()->create(array_merge([
            'bank_statement_import_id' => $import->getKey(),
            'fingerprint' => uniqid('fee_', true),
            'month_key' => '2026-08',
            'booking_date' => '2026-08-03',
            'direction' => BankStatementDirection::Debit,
            'amount' => 40,
            'currency' => 'EUR',
            'no_document_needed' => false,
        ], $overrides));
    }
}
