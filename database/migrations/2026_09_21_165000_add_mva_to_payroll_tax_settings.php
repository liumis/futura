<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_tax_settings', 'mva')) {
            Schema::table('payroll_tax_settings', function (Blueprint $table): void {
                $table->decimal('mva', 12, 4)->nullable()->after('mma');
            });
        }

        // 2025 VMI baseline (MMA 1038 × 1.65 = 1712.70; MVA 6.35 × 1.65 = 10.4775).
        if (! DB::table('payroll_tax_settings')->where('year', 2025)->exists()) {
            $now = now();
            DB::table('payroll_tax_settings')->insert([
                'year' => 2025,
                'mma' => 1038.00,
                'mva' => 6.3500,
                'npd_max' => 747.00,
                'npd_coefficient' => 0.49,
                'npd_disability_0_25' => 1127.00,
                'npd_disability_30_55' => 1057.00,
                'employee_sodra_rate' => 0.1950,
                'gpm_rate' => 0.2000,
                'employer_sodra_permanent_rate' => 0.0177,
                'employer_sodra_fixed_term_rate' => 0.0249,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('payroll_tax_settings')
                ->where('year', 2025)
                ->whereNull('mva')
                ->update(['mva' => 6.3500, 'updated_at' => now()]);
        }

        // 2026 MMA 1153; MVA 7.00.
        DB::table('payroll_tax_settings')
            ->where('year', 2026)
            ->whereNull('mva')
            ->update(['mva' => 7.0000, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_tax_settings', 'mva')) {
            Schema::table('payroll_tax_settings', function (Blueprint $table): void {
                $table->dropColumn('mva');
            });
        }
    }
};
