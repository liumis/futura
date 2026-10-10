<?php

namespace Tests\Unit;

use App\Enums\BankStatementDirection;
use App\Exceptions\BankStatementDuplicateException;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Services\BankStatements\BankStatementExcelParser;
use App\Services\BankStatements\BankStatementImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementExcelParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_accounting_cashflow_xls_income_and_outcome(): void
    {
        $path = base_path('tests/Fixtures/bank-statements/cashflow-seb-9371.xls');
        $this->assertFileExists($path);

        $parsed = app(BankStatementExcelParser::class)->parse($path);

        $this->assertSame('SEB AB', $parsed['bank_name']);
        $this->assertSame('LT677044090113979371', $parsed['account_iban']);
        $this->assertSame('EUR', $parsed['currency']);
        $this->assertSame('2026-04-01', $parsed['period_from']);
        $this->assertSame('2026-07-31', $parsed['period_to']);
        $this->assertGreaterThan(50, count($parsed['transactions']));

        $firstIncome = collect($parsed['transactions'])
            ->first(fn (array $row): bool => $row['direction'] === BankStatementDirection::Credit);

        $this->assertNotNull($firstIncome);
        $this->assertSame('2026-04-10', $firstIncome['booking_date']);
        $this->assertSame(967.06, $firstIncome['amount']);
        $this->assertStringContainsString('Perkėlimas', (string) $firstIncome['counterpart_name']);

        $anthropic = collect($parsed['transactions'])
            ->first(fn (array $row): bool => str_contains((string) ($row['counterpart_name'] ?? ''), 'Anthropic'));

        if ($anthropic !== null) {
            $this->assertSame('Anthropic, PBC', $anthropic['counterpart_name']);
            $this->assertSame(BankStatementDirection::Debit, $anthropic['direction']);
            $this->assertGreaterThan(0, $anthropic['amount']);
        }

        $firstOutcome = collect($parsed['transactions'])
            ->first(fn (array $row): bool => $row['direction'] === BankStatementDirection::Debit);

        $this->assertNotNull($firstOutcome);
        $this->assertGreaterThan(0, $firstOutcome['amount']);
    }

    public function test_importer_stores_excel_lines_and_skips_duplicates(): void
    {
        $path = base_path('tests/Fixtures/bank-statements/cashflow-seb-9371.xls');
        $importer = app(BankStatementImporter::class);

        $first = $importer->importExcel($path, 'cashflow-seb-9371.xls', comment: 'Accounting export');
        $this->assertGreaterThan(50, $first['imported']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame('Accounting export', $first['import']->comment);
        $this->assertSame('SEB AB', $first['import']->bank_name);

        try {
            $importer->importExcel($path, 'cashflow-seb-9371.xls');
            $this->fail('Expected a duplicate statement to be rejected.');
        } catch (BankStatementDuplicateException $exception) {
            $this->assertMatchesRegularExpression('/already (imported|in the system)/i', $exception->getMessage());
        }

        $this->assertSame(1, BankStatementImport::query()->count());
        $this->assertSame(
            $first['imported'],
            BankStatementTransaction::query()->where('account_iban', 'LT677044090113979371')->count(),
        );
    }
}
