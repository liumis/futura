<?php

use App\Exceptions\BankStatementDuplicateException;
use App\Services\BankStatements\BankStatementImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Import SEB bank statement lines from Bankas_11.01-20.pdf (2026-11-01…2026-11-20).
 * Snapshot: database/data/bank-statement-2026-11-01-20.json.
 * Idempotent: skips fingerprints that already exist; no-op if all lines were imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        $path = database_path('data/bank-statement-2026-11-01-20.json');
        if (! is_file($path)) {
            return;
        }

        /** @var array{
         *     source?: string,
         *     message_id?: ?string,
         *     statement_id?: ?string,
         *     account_iban?: ?string,
         *     bank_name?: ?string,
         *     currency?: ?string,
         *     period_from?: ?string,
         *     period_to?: ?string,
         *     transactions?: list<array<string, mixed>>
         * } $payload
         */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $transactions = $payload['transactions'] ?? [];
        if ($transactions === []) {
            return;
        }

        $parsed = [
            'message_id' => $payload['message_id'] ?? null,
            'statement_id' => $payload['statement_id'] ?? null,
            'account_iban' => $payload['account_iban'] ?? null,
            'bank_name' => $payload['bank_name'] ?? null,
            'currency' => $payload['currency'] ?? 'EUR',
            'period_from' => $payload['period_from'] ?? null,
            'period_to' => $payload['period_to'] ?? null,
            'transactions' => $transactions,
        ];

        try {
            app(BankStatementImporter::class)->importFromParsed(
                $parsed,
                (string) ($payload['source'] ?? 'Bankas_11.01-20.pdf'),
                comment: 'Migration import Bankas 2026-11-01…20',
                contentHash: hash_file('sha256', $path) ?: null,
            );
        } catch (BankStatementDuplicateException) {
            // Already imported on this environment.
        }
    }

    public function down(): void
    {
        // Keep imported bank lines; not safely reversible.
    }
};
