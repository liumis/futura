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

        $keepUnique = 'employee_monthly_payments_employee_id_payment_date_unique';
        $toDrop = [];

        foreach (DB::select('SHOW INDEX FROM `employee_monthly_payments`') as $row) {
            $name = (string) ($row->Key_name ?? '');
            if ($name === '' || $name === 'PRIMARY' || $name === $keepUnique) {
                continue;
            }

            $nonUnique = (int) ($row->Non_unique ?? 1);
            $column = strtolower((string) ($row->Column_name ?? ''));
            $nameLower = strtolower($name);

            if ($nonUnique === 0
                || str_contains($nameLower, 'year_month')
                || in_array($column, ['year', 'month'], true)) {
                $toDrop[$name] = true;
            }
        }

        foreach ([
            'employee_monthly_payments_employee_id_year_month_unique',
            'employee_monthly_payments_employee_id_year_month_un',
            'employee_monthly_payments_year_month_index',
        ] as $known) {
            $toDrop[$known] = true;
        }

        foreach (array_keys($toDrop) as $name) {
            $safe = str_replace('`', '', $name);
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

        $hasPaymentDateUnique = collect(DB::select('SHOW INDEX FROM `employee_monthly_payments`'))
            ->contains(fn (object $row): bool => ($row->Key_name ?? null) === $keepUnique);

        if (Schema::hasColumn('employee_monthly_payments', 'payment_date') && ! $hasPaymentDateUnique) {
            try {
                Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                    $table->unique(['employee_id', 'payment_date']);
                });
            } catch (\Throwable) {
                // May already exist under another name.
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
    }

    public function down(): void
    {
        //
    }
};
