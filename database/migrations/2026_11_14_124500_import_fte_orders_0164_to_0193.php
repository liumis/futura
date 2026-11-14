<?php

use App\Services\FuturaSalesInvoicePdfImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Import FTE0164–FTE0193 sales as HIST|CODE|DATE|FTExxxx orders + invoice headers
 * (no PDF binaries). Snapshot: database/data/fte-orders-0164-0193.json.
 * Idempotent by invoice number / FTE-suffixed order name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('orders')) {
            return;
        }

        $path = database_path('data/fte-orders-0164-0193.json');
        if (! is_file($path)) {
            return;
        }

        /** @var array{rows?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $payload['rows'] ?? [];
        if ($rows === []) {
            return;
        }

        app(FuturaSalesInvoicePdfImporter::class)->importSnapshotRows($rows, linkBank: false);
    }

    public function down(): void
    {
        // Keep imported HIST orders / invoices; not safely reversible.
    }
};
