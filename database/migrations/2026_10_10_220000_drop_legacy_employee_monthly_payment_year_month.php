<?php

use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production still had employee_id+year+month unique after payment_date replaced those columns.
 * Dump inserts omit year/month; MySQL fills 0/0 and trips the leftover unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_monthly_payments')) {
            return;
        }

        $hasYear = Schema::hasColumn('employee_monthly_payments', 'year');
        $hasMonth = Schema::hasColumn('employee_monthly_payments', 'month');
        $hasPaymentDate = Schema::hasColumn('employee_monthly_payments', 'payment_date');

        if ($hasYear && $hasMonth && $hasPaymentDate) {
            $rows = DB::table('employee_monthly_payments')
                ->whereNull('payment_date')
                ->get(['id', 'year', 'month']);

            foreach ($rows as $row) {
                if ($row->year === null || $row->month === null) {
                    continue;
                }

                DB::table('employee_monthly_payments')->where('id', $row->id)->update([
                    'payment_date' => sprintf('%04d-%02d-01', (int) $row->year, (int) $row->month),
                ]);
            }
        }

        SchemaForeignKeys::dropOnColumn('employee_monthly_payments', 'employee_id');
        $this->dropIndexesTouching(['year', 'month']);

        if ($hasYear || $hasMonth) {
            Schema::table('employee_monthly_payments', function (Blueprint $table) use ($hasYear, $hasMonth): void {
                $drop = array_values(array_filter([
                    $hasYear ? 'year' : null,
                    $hasMonth ? 'month' : null,
                ]));

                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (! SchemaForeignKeys::hasOnColumn('employee_monthly_payments', 'employee_id')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->foreign('employee_id')
                    ->references('id')
                    ->on('employees')
                    ->cascadeOnDelete();
            });
        }

        if ($hasPaymentDate && ! $this->hasIndexNamed('employee_monthly_payments_employee_id_payment_date_unique')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->unique(['employee_id', 'payment_date']);
            });
        }
    }

    public function down(): void
    {
        // Irreversible cleanup of a leftover schema mismatch.
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropIndexesTouching(array $columns): void
    {
        $indexes = Schema::getConnection()->getSchemaBuilder()->getIndexes('employee_monthly_payments');

        foreach ($indexes as $index) {
            $indexCols = $index['columns'] ?? [];
            if (array_intersect($columns, $indexCols) === []) {
                continue;
            }

            $name = $index['name'] ?? null;
            if (! is_string($name) || $name === 'PRIMARY') {
                continue;
            }

            try {
                Schema::table('employee_monthly_payments', function (Blueprint $table) use ($name): void {
                    $table->dropIndex($name);
                });
            } catch (\Throwable) {
                try {
                    DB::statement('ALTER TABLE `employee_monthly_payments` DROP INDEX `'.$name.'`');
                } catch (\Throwable) {
                    // Already gone.
                }
            }
        }
    }

    private function hasIndexNamed(string $name): bool
    {
        $indexes = Schema::getConnection()->getSchemaBuilder()->getIndexes('employee_monthly_payments');

        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
