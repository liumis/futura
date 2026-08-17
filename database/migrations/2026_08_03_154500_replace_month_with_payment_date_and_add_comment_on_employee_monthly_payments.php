<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_monthly_payments', 'payment_date')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->date('payment_date')->nullable()->after('employee_id');
            });
        }

        if (! Schema::hasColumn('employee_monthly_payments', 'comment')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->text('comment')->nullable()->after('bonus_payment');
            });
        }

        if (Schema::hasColumn('employee_monthly_payments', 'year')
            && Schema::hasColumn('employee_monthly_payments', 'month')) {
            $rows = DB::table('employee_monthly_payments')->get(['id', 'year', 'month']);
            foreach ($rows as $row) {
                DB::table('employee_monthly_payments')->where('id', $row->id)->update([
                    'payment_date' => sprintf('%04d-%02d-01', (int) $row->year, (int) $row->month),
                ]);
            }

            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->dropUnique(['employee_id', 'year', 'month']);
                $table->dropIndex(['year', 'month']);
                $table->dropColumn(['year', 'month']);
            });
        }

        $this->ensurePaymentDateIndex();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employee_monthly_payments', 'year')) {
            Schema::table('employee_monthly_payments', function (Blueprint $table): void {
                $table->unsignedSmallInteger('year')->nullable()->after('employee_id');
                $table->unsignedTinyInteger('month')->nullable()->after('year');
            });
        }

        if (Schema::hasColumn('employee_monthly_payments', 'payment_date')) {
            $rows = DB::table('employee_monthly_payments')->get(['id', 'payment_date']);
            foreach ($rows as $row) {
                $date = $row->payment_date ? date_create((string) $row->payment_date) : null;
                DB::table('employee_monthly_payments')->where('id', $row->id)->update([
                    'year' => $date ? (int) $date->format('Y') : null,
                    'month' => $date ? (int) $date->format('n') : null,
                ]);
            }
        }

        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            if ($this->hasIndexNamed('employee_monthly_payments_employee_id_payment_date_unique')) {
                $table->dropUnique(['employee_id', 'payment_date']);
            }

            if ($this->hasIndexNamed('employee_monthly_payments_payment_date_index')) {
                $table->dropIndex(['payment_date']);
            }

            if (Schema::hasColumn('employee_monthly_payments', 'payment_date')) {
                $table->dropColumn('payment_date');
            }

            if (Schema::hasColumn('employee_monthly_payments', 'comment')) {
                $table->dropColumn('comment');
            }
        });

        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            $table->unique(['employee_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });
    }

    protected function ensurePaymentDateIndex(): void
    {
        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            if (! $this->hasIndexNamed('employee_monthly_payments_employee_id_payment_date_unique')) {
                $table->unique(['employee_id', 'payment_date']);
            }

            if (! $this->hasIndexNamed('employee_monthly_payments_payment_date_index')) {
                $table->index('payment_date');
            }
        });
    }

    protected function hasIndexNamed(string $name): bool
    {
        $sm = Schema::getConnection()->getSchemaBuilder();
        $indexes = $sm->getIndexes('employee_monthly_payments');

        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
