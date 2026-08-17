<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_tax_settings')) {
            Schema::create('payroll_tax_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedSmallInteger('year')->unique();
                $table->decimal('mma', 12, 2);
                $table->decimal('npd_max', 12, 2);
                $table->decimal('npd_coefficient', 8, 4);
                $table->decimal('npd_disability_0_25', 12, 2);
                $table->decimal('npd_disability_30_55', 12, 2);
                $table->decimal('employee_sodra_rate', 8, 4);
                $table->decimal('gpm_rate', 8, 4);
                $table->timestamps();
            });
        }

        if (! DB::table('payroll_tax_settings')->where('year', 2026)->exists()) {
            $now = now();
            DB::table('payroll_tax_settings')->insert([
                'year' => 2026,
                'mma' => 1153.00,
                'npd_max' => 747.00,
                'npd_coefficient' => 0.49,
                'npd_disability_0_25' => 1127.00,
                'npd_disability_30_55' => 1057.00,
                'employee_sodra_rate' => 0.1950,
                'gpm_rate' => 0.2000,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasColumn('employees', 'npd_type')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->string('npd_type', 40)->default('standard')->after('shareholder_percentage');
                $table->boolean('second_pillar_enrolled')->default(false)->after('npd_type');
                $table->decimal('second_pillar_rate', 8, 4)->nullable()->after('second_pillar_enrolled');
            });
        }

        if (! Schema::hasColumn('employee_monthly_payments', 'gross_amount')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->decimal('gross_amount', 12, 2)->nullable()->after('bonus_payment');
                $table->decimal('npd_amount', 12, 2)->nullable()->after('gross_amount');
                $table->decimal('sodra_employee_amount', 12, 2)->nullable()->after('npd_amount');
                $table->decimal('gpm_amount', 12, 2)->nullable()->after('sodra_employee_amount');
                $table->decimal('net_amount', 12, 2)->nullable()->after('gpm_amount');
            });
        }
    }

    public function down(): void
    {
        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            $table->dropColumn([
                'gross_amount',
                'npd_amount',
                'sodra_employee_amount',
                'gpm_amount',
                'net_amount',
            ]);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['npd_type', 'second_pillar_enrolled', 'second_pillar_rate']);
        });

        Schema::dropIfExists('payroll_tax_settings');
    }
};
