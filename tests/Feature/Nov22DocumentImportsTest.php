<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Nov22DocumentImportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_likuciai_2026_11_20_snapshot_shape(): void
    {
        $path = database_path('data/stock-likuciai-2026-11-20.json');
        $this->assertFileExists($path);

        /** @var array{as_of?: string, row_count?: int, rows?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('2026-11-20', $payload['as_of'] ?? null);
        $this->assertSame(295, $payload['row_count'] ?? null);
        $this->assertCount(295, $payload['rows'] ?? []);
        $this->assertSame('stock', $payload['rows'][0]['section'] ?? null);
    }

    public function test_bank_statement_2026_11_01_20_snapshot_shape(): void
    {
        $path = database_path('data/bank-statement-2026-11-01-20.json');
        $this->assertFileExists($path);

        /** @var array{period_from?: string, period_to?: string, transaction_count?: int, transactions?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('2026-11-01', $payload['period_from'] ?? null);
        $this->assertSame('2026-11-20', $payload['period_to'] ?? null);
        $this->assertSame(41, $payload['transaction_count'] ?? null);
        $this->assertCount(41, $payload['transactions'] ?? []);
        $this->assertNotEmpty($payload['transactions'][0]['booking_date'] ?? null);
    }

    public function test_mayur_cargos_snapshot_shape(): void
    {
        $path = database_path('data/mayur-cargos-9611000188-0190.json');
        $this->assertFileExists($path);

        /** @var array{cargos?: list<array<string, mixed>>, expense_invoices?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(2, $payload['cargos'] ?? []);
        $this->assertCount(2, $payload['expense_invoices'] ?? []);

        $trackings = array_column($payload['cargos'] ?? [], 'tracking');
        sort($trackings);
        $this->assertSame(['9611000188', '9611000190'], $trackings);

        $received = collect($payload['cargos'] ?? [])->firstWhere('tracking', '9611000190');
        $this->assertSame('received', $received['status'] ?? null);
        $this->assertSame(509.0, (float) ($received['customs']['tax_amount'] ?? 0));
        $this->assertCount(6, $received['lines'] ?? []);
    }
}
