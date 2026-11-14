<?php

use App\Services\ExternalStockImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile product stock/costs from Lik_Dat 2026-11-09 Excel snapshot.
 * Snapshot: database/data/stock-likuciai-2026-11-09.json.
 * Idempotent: re-running applies the same quantities/costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('products')) {
            return;
        }

        $path = database_path('data/stock-likuciai-2026-11-09.json');
        if (! is_file($path)) {
            return;
        }

        /** @var array{rows?: list<array<string, mixed>>, source?: string} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $payload['rows'] ?? [];
        if ($rows === []) {
            return;
        }

        app(ExternalStockImporter::class)->importFromRows(
            $rows,
            $payload['source'] ?? 'stock-likuciai-2026-11-09.json',
        );
    }

    public function down(): void
    {
        // Keep reconciled stock; not safely reversible.
    }
};
