<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')
            || Schema::hasColumn('bank_statement_transactions', 'document_id')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            $table->foreignId('document_id')
                ->nullable()
                ->after('employee_monthly_payment_id')
                ->constrained('documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')
            || ! Schema::hasColumn('bank_statement_transactions', 'document_id')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
