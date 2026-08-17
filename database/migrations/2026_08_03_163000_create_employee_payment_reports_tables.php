<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_payment_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status', 40)->default('created');
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('employee_payment_report_approvers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_payment_report_id')->constrained('employee_payment_reports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_auto_approved')->default(false);
            $table->timestamps();

            $table->unique(['employee_payment_report_id', 'user_id'], 'payment_report_user_unique');
        });

        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            $table->foreignId('employee_payment_report_id')
                ->nullable()
                ->after('status')
                ->constrained('employee_payment_reports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_payment_report_id');
        });

        Schema::dropIfExists('employee_payment_report_approvers');
        Schema::dropIfExists('employee_payment_reports');
    }
};
