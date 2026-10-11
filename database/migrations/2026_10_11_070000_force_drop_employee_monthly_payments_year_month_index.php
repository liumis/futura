<?php

use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Previous cleanup migration could report success while leaving the MySQL unique
 * employee_id+year+month index in place. Force-drop via information_schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_monthly_payments')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql'
            && Schema::getConnection()->getDriverName() !== 'mariadb') {
            return;
        }

        SchemaForeignKeys::dropOnColumn('employee_monthly_payments', 'employee_id');

        $schema = Schema::getConnection()->getDatabaseName();
        $indexRows = DB::select(
            'SELECT DISTINCT INDEX_NAME AS name
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND INDEX_NAME <> ?
               AND (
                    COLUMN_NAME IN (?, ?)
                    OR INDEX_NAME LIKE ?
               )',
            [
                $schema,
                'employee_monthly_payments',
                'PRIMARY',
                'year',
                'month',
                '%year_month%',
            ],
        );

        $names = collect($indexRows)->pluck('name')->filter()->unique()->values()->all();
        $names[] = 'employee_monthly_payments_employee_id_year_month_unique';
        $names[] = 'employee_monthly_payments_employee_id_year_month_un';
        $names[] = 'employee_monthly_payments_year_month_index';

        foreach (array_unique($names) as $name) {
            $safe = str_replace('`', '', (string) $name);
            try {
                DB::statement("ALTER TABLE `employee_monthly_payments` DROP INDEX `{$safe}`");
            } catch (\Throwable) {
                // Already gone.
            }
        }

        foreach (['year', 'month'] as $column) {
            if (! Schema::hasColumn('employee_monthly_payments', $column)) {
                continue;
            }

            try {
                DB::statement("ALTER TABLE `employee_monthly_payments` DROP COLUMN `{$column}`");
            } catch (\Throwable) {
                // Already gone.
            }
        }

        if (! SchemaForeignKeys::hasOnColumn('employee_monthly_payments', 'employee_id')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->foreign('employee_id')
                    ->references('id')
                    ->on('employees')
                    ->cascadeOnDelete();
            });
        }

        $hasPaymentDateUnique = collect(
            DB::select(
                'SELECT INDEX_NAME AS name
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                   AND INDEX_NAME = ?
                 LIMIT 1',
                [$schema, 'employee_monthly_payments', 'employee_monthly_payments_employee_id_payment_date_unique'],
            ),
        )->isNotEmpty();

        if (Schema::hasColumn('employee_monthly_payments', 'payment_date') && ! $hasPaymentDateUnique) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->unique(['employee_id', 'payment_date']);
            });
        }
    }

    public function down(): void
    {
        //
    }
};
