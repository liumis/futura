<?php

use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_payment_reports')) {
            Schema::create('employee_payment_reports', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('status', 40)->default('created');
                $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->index('status');
            });
        }

        if (! Schema::hasTable('employee_payment_report_approvers')) {
            Schema::create('employee_payment_report_approvers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_payment_report_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('approved_at')->nullable();
                $table->boolean('is_auto_approved')->default(false);
                $table->timestamps();

                $table->unique(['employee_payment_report_id', 'user_id'], 'payment_report_user_unique');
            });
        }

        SchemaForeignKeys::ensure(
            'employee_payment_report_approvers',
            'employee_payment_report_id',
            'employee_payment_reports',
            'epr_approvers_report_fk',
        );
        SchemaForeignKeys::ensure(
            'employee_payment_report_approvers',
            'user_id',
            'users',
            'epr_approvers_user_fk',
        );

        if (! Schema::hasColumn('employee_monthly_payments', 'employee_payment_report_id')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->unsignedBigInteger('employee_payment_report_id')->nullable()->after('status');
            });
        }

        SchemaForeignKeys::ensure(
            'employee_monthly_payments',
            'employee_payment_report_id',
            'employee_payment_reports',
            'emp_payments_report_fk',
            'null',
        );
    }

    public function down(): void
    {
        SchemaForeignKeys::dropColumnIfExists('employee_monthly_payments', 'employee_payment_report_id');
        Schema::dropIfExists('employee_payment_report_approvers');
        Schema::dropIfExists('employee_payment_reports');
    }
};
