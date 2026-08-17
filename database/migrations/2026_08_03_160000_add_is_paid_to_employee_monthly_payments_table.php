<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_monthly_payments', 'is_paid')
            || ! Schema::hasColumn('employee_monthly_payments', 'paid_at')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                if (! Schema::hasColumn('employee_monthly_payments', 'is_paid')) {
                    $table->boolean('is_paid')->default(false)->after('comment');
                }
                if (! Schema::hasColumn('employee_monthly_payments', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('is_paid');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            $table->dropColumn(['is_paid', 'paid_at']);
        });
    }
};
