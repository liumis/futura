<?php

use App\Services\UnbalancedFinanceMonthsFixer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Re-apply unbalanced-month repairs on prod.
 *
 * The earlier 111500 migration often ran before bootstrap re-imported the dirty dump
 * (or on an empty bank table), so the fix never stuck. This migration + post-bootstrap
 * artisan command ensure the repair lands on real finance data.
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
