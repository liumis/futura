<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_trip_requests', function (Blueprint $table): void {
            $table->unsignedSmallInteger('tax_year')->nullable()->after('amount');
            $table->unsignedTinyInteger('tax_month')->nullable()->after('tax_year');
            $table->decimal('salary_snapshot', 12, 2)->nullable()->after('tax_month');
            $table->decimal('salary_threshold_snapshot', 12, 2)->nullable()->after('salary_snapshot');
            $table->boolean('is_low_salary_case')->default(false)->after('salary_threshold_snapshot');
            $table->decimal('month_free_cap_snapshot', 12, 2)->nullable()->after('is_low_salary_case');
            $table->decimal('non_taxable_amount', 12, 2)->default(0)->after('month_free_cap_snapshot');
            $table->decimal('taxable_amount', 12, 2)->default(0)->after('non_taxable_amount');
            $table->decimal('gpm_amount', 12, 2)->default(0)->after('taxable_amount');
            $table->decimal('payable_amount', 12, 2)->default(0)->after('gpm_amount');
        });
    }

    public function down(): void
    {
        Schema::table('business_trip_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'tax_year',
                'tax_month',
                'salary_snapshot',
                'salary_threshold_snapshot',
                'is_low_salary_case',
                'month_free_cap_snapshot',
                'non_taxable_amount',
                'taxable_amount',
                'gpm_amount',
                'payable_amount',
            ]);
        });
    }
};
