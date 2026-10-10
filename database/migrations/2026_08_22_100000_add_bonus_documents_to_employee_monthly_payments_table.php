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
                $table->unsignedBigInteger('bonus_order_document_id')->nullable()->after('payslip_document_id');
                $table->foreign('bonus_order_document_id', 'emp_mp_bonus_order_doc_fk')
                    ->references('id')
                    ->on('documents')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('employee_monthly_payments', 'shareholder_decision_document_id')) {
                $table->unsignedBigInteger('shareholder_decision_document_id')->nullable()->after('bonus_order_document_id');
                $table->foreign('shareholder_decision_document_id', 'emp_mp_shareholder_doc_fk')
                    ->references('id')
                    ->on('documents')
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
                $table->dropForeign('emp_mp_shareholder_doc_fk');
                $table->dropColumn('shareholder_decision_document_id');
            }

            if (Schema::hasColumn('employee_monthly_payments', 'bonus_order_document_id')) {
                $table->dropForeign('emp_mp_bonus_order_doc_fk');
                $table->dropColumn('bonus_order_document_id');
            }
        });
    }
};
