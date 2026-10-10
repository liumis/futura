<?php

use App\Services\BankStatements\BankStatementDirectionResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')
            || ! Schema::hasColumn('bank_statement_transactions', 'no_document_needed')) {
            return;
        }

        DB::table('bank_statement_transactions')
            ->whereNull('invoice_id')
            ->whereNull('payment_id')
            ->where('no_document_needed', false)
            ->where(function ($query): void {
                foreach (BankStatementDirectionResolver::bankServiceFeeLikePatterns() as $pattern) {
                    $query
                        ->orWhere('description', 'like', $pattern)
                        ->orWhere('counterpart_name', 'like', $pattern);
                }
            })
            ->update(['no_document_needed' => true]);
    }

    public function down(): void
    {
        // Keep flags; they can be corrected from the Bank statements page.
    }
};
