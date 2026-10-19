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
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        $missing = ! Schema::hasTable('employee_monthly_payments');
        $needsRebuild = $missing
            || Schema::hasColumn('employee_monthly_payments', 'year')
            || Schema::hasColumn('employee_monthly_payments', 'month')
            || collect(DB::select('SHOW INDEX FROM `employee_monthly_payments`'))
                ->contains(fn (object $row): bool => str_contains(strtolower((string) ($row->Key_name ?? '')), 'year_month'));

        if (! $needsRebuild) {
            return;
        }

        $schema = Schema::getConnection()->getDatabaseName();

        if (! $missing) {
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
        }

        DB::statement('DROP TABLE IF EXISTS `employee_monthly_payments`');

        Schema::create('employee_monthly_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('payment_date')->nullable();
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->decimal('bonus_payment', 12, 2)->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('employee_payment_report_id')->nullable();
            $table->decimal('gross_amount', 12, 2)->nullable();
            $table->decimal('npd_amount', 12, 2)->nullable();
            $table->decimal('sodra_employee_amount', 12, 2)->nullable();
            $table->decimal('gpm_amount', 12, 2)->nullable();
            $table->decimal('net_amount', 12, 2)->nullable();
            $table->decimal('sodra_employer_amount', 12, 2)->nullable();
            $table->unsignedBigInteger('payslip_document_id')->nullable();
            $table->unsignedBigInteger('bonus_order_document_id')->nullable();
            $table->unsignedBigInteger('shareholder_decision_document_id')->nullable();
            $table->timestamps();

            // Short FK/index names — MySQL caps identifiers at 64 chars.
            $table->foreign('employee_id', 'emp_mp_employee_fk')
                ->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('employee_payment_report_id', 'emp_payments_report_fk')
                ->references('id')->on('employee_payment_reports')->nullOnDelete();
            $table->foreign('payslip_document_id', 'emp_mp_payslip_doc_fk')
                ->references('id')->on('documents')->nullOnDelete();
            $table->foreign('bonus_order_document_id', 'emp_mp_bonus_order_doc_fk')
                ->references('id')->on('documents')->nullOnDelete();
            $table->foreign('shareholder_decision_document_id', 'emp_mp_shareholder_doc_fk')
                ->references('id')->on('documents')->nullOnDelete();

            $table->unique(['employee_id', 'payment_date'], 'emp_mp_employee_payment_date_uq');
            $table->index('payment_date', 'emp_mp_payment_date_idx');
        });

        if (Schema::hasTable('bank_statement_transactions')
            && Schema::hasColumn('bank_statement_transactions', 'employee_monthly_payment_id')) {
            \App\Support\SchemaForeignKeys::ensure(
                'bank_statement_transactions',
                'employee_monthly_payment_id',
                'employee_monthly_payments',
                'bst_emp_monthly_pay_fk',
                'null',
            );
        }
    }

    public function down(): void
    {
        //
    }
};
