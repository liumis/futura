<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('bank_statement_transactions', 'no_document_needed')) {
                $table->boolean('no_document_needed')
                    ->default(false)
                    ->after('payment_id');
            }
        });

        // Prefill known internal account transfers.
        DB::table('bank_statement_transactions')
            ->where(function ($query): void {
                $query
                    ->where('description', 'like', '%Tarp savo%')
                    ->orWhere('description', 'like', '%Between account%')
                    ->orWhere('description', 'like', '%perkėlimas tarp%')
                    ->orWhere('description', 'like', '%perkelimas tarp%');
            })
            ->whereNull('invoice_id')
            ->whereNull('payment_id')
            ->update(['no_document_needed' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('bank_statement_transactions', 'no_document_needed')) {
                $table->dropColumn('no_document_needed');
            }
        });
    }
};
