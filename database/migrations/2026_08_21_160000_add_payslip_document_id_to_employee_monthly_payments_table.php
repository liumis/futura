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
            if (! Schema::hasColumn('employee_monthly_payments', 'payslip_document_id')) {
                $table->foreignId('payslip_document_id')
                    ->nullable()
                    ->after('employee_payment_report_id')
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

        if (Schema::hasColumn('employee_monthly_payments', 'payslip_document_id')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('payslip_document_id');
            });
        }
    }
};
