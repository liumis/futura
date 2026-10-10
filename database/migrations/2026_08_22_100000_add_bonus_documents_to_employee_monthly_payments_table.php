<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_monthly_payments')) {
            return;
        }

        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_monthly_payments', 'bonus_order_document_id')) {
                $table->foreignId('bonus_order_document_id')
                    ->nullable()
                    ->after('payslip_document_id')
                    ->constrained('documents')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('employee_monthly_payments', 'shareholder_decision_document_id')) {
                $table->foreignId('shareholder_decision_document_id')
                    ->nullable()
                    ->after('bonus_order_document_id')
                    ->constrained('documents')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_monthly_payments')) {
            return;
        }

        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('employee_monthly_payments', 'shareholder_decision_document_id')) {
                $table->dropConstrainedForeignId('shareholder_decision_document_id');
            }

            if (Schema::hasColumn('employee_monthly_payments', 'bonus_order_document_id')) {
                $table->dropConstrainedForeignId('bonus_order_document_id');
            }
        });
    }
};
