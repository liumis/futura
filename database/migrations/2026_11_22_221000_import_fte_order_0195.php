<?php

use App\Services\FuturaSalesInvoicePdfImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Import FTE0195 (2026-11-16, Vinilas) as HIST|VINILAS|2026-11-16|FTE0195 order + invoice.
 * Snapshot: database/data/fte-orders-0195.json.
 * Idempotent by invoice number / FTE-suffixed order name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('orders')) {
            return;
        }

        $path = database_path('data/fte-orders-0195.json');
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
        // Keep imported HIST order / invoice; not safely reversible.
    }
};
