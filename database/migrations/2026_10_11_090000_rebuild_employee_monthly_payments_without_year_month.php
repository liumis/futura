<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nuclear fix for Cloud: employee_id FK can pin the year/month unique so DROP INDEX
 * is silently impossible. Drop every FK via information_schema, then rebuild the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_monthly_payments')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $needsRebuild = Schema::hasColumn('employee_monthly_payments', 'year')
            || Schema::hasColumn('employee_monthly_payments', 'month')
            || collect(DB::select('SHOW INDEX FROM `employee_monthly_payments`'))
                ->contains(fn (object $row): bool => str_contains(strtolower((string) ($row->Key_name ?? '')), 'year_month'));

        if (! $needsRebuild) {
            return;
        }

        $schema = Schema::getConnection()->getDatabaseName();

        $inbound = DB::select(
            'SELECT TABLE_NAME AS tbl, CONSTRAINT_NAME AS name
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE REFERENCED_TABLE_SCHEMA = ?
               AND REFERENCED_TABLE_NAME = ?
               AND CONSTRAINT_NAME IS NOT NULL',
            [$schema, 'employee_monthly_payments'],
        );

        foreach ($inbound as $fk) {
            $tbl = str_replace('`', '', (string) ($fk->tbl ?? ''));
            $name = str_replace('`', '', (string) ($fk->name ?? ''));
            if ($tbl === '' || $name === '') {
                continue;
            }

            try {
                DB::statement("ALTER TABLE `{$tbl}` DROP FOREIGN KEY `{$name}`");
            } catch (\Throwable) {
                // Already gone.
            }
        }

        $outbound = DB::select(
            'SELECT CONSTRAINT_NAME AS name
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$schema, 'employee_monthly_payments', 'FOREIGN KEY'],
        );

        foreach ($outbound as $fk) {
            $name = str_replace('`', '', (string) ($fk->name ?? ''));
            if ($name === '') {
                continue;
            }

            try {
                DB::statement("ALTER TABLE `employee_monthly_payments` DROP FOREIGN KEY `{$name}`");
            } catch (\Throwable) {
                // Already gone.
            }
        }

        DB::statement('DROP TABLE IF EXISTS `employee_monthly_payments`');

        Schema::create('employee_monthly_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('payment_date')->nullable();
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->decimal('bonus_payment', 12, 2)->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('employee_payment_report_id')->nullable()->constrained('employee_payment_reports')->nullOnDelete();
            $table->decimal('gross_amount', 12, 2)->nullable();
            $table->decimal('npd_amount', 12, 2)->nullable();
            $table->decimal('sodra_employee_amount', 12, 2)->nullable();
            $table->decimal('gpm_amount', 12, 2)->nullable();
            $table->decimal('net_amount', 12, 2)->nullable();
            $table->decimal('sodra_employer_amount', 12, 2)->nullable();
            $table->foreignId('payslip_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('bonus_order_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('shareholder_decision_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'payment_date']);
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        //
    }
};
