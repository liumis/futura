<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_monthly_payments', 'status')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->string('status', 20)->default('open')->after('comment');
            });
        }

        DB::table('employee_monthly_payments')
            ->where('is_paid', true)
            ->update(['status' => 'payed']);
    }

    public function down(): void
    {
        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
