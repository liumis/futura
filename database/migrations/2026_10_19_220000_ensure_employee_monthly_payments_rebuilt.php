<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-run the rebuild if an earlier attempt dropped the table / left year_month unique.
 * Delegates to the same SQL as 2026_10_11_090000 by requiring that file's logic inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $missing = ! Schema::hasTable('employee_monthly_payments');
        $needsRebuild = $missing
            || Schema::hasColumn('employee_monthly_payments', 'year')
            || Schema::hasColumn('employee_monthly_payments', 'month')
            || (
                Schema::hasTable('employee_monthly_payments')
                && collect(DB::select('SHOW INDEX FROM `employee_monthly_payments`'))
                    ->contains(fn (object $row): bool => str_contains(strtolower((string) ($row->Key_name ?? '')), 'year_month'))
            );

        if (! $needsRebuild) {
            return;
        }

        // Reuse the prior migration class body by including and running its up().
        $prior = require database_path('migrations/2026_10_11_090000_rebuild_employee_monthly_payments_without_year_month.php');
        $prior->up();
    }

    public function down(): void
    {
        //
    }
};
