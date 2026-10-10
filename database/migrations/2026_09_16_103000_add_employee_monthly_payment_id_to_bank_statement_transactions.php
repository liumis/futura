<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')
            || Schema::hasColumn('bank_statement_transactions', 'employee_monthly_payment_id')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            $table->foreignId('employee_monthly_payment_id')
                ->nullable()
                ->after('payment_id')
                ->constrained('employee_monthly_payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bank_statement_transactions', 'employee_monthly_payment_id')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_monthly_payment_id');
        });
    }
};
