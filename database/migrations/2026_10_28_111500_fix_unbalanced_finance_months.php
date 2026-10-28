<?php

use App\Services\UnbalancedFinanceMonthsFixer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Clean unbalanced-month causes (delegates to UnbalancedFinanceMonthsFixer).
 * Skipped in testing. Also re-run after prod bootstrap via app:cloud-deploy-database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        UnbalancedFinanceMonthsFixer::run();
    }

    public function down(): void
    {
        // Data repair — intentionally not reversed.
    }
};
