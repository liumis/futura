<?php

use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dividends')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable();

            return;
        }

        if (! Schema::hasColumn('dividends', 'shareholder_id')) {
            Schema::table('dividends', function (Blueprint $table): void {
                $table->foreignId('shareholder_id')
                    ->nullable()
                    ->after('id');
            });
        }

        SchemaForeignKeys::ensure(
            'dividends',
            'shareholder_id',
            'shareholders',
            'dividends_shareholder_id_fk',
        );

        if (Schema::hasColumn('dividends', 'employee_id')) {
            Schema::table('dividends', function (Blueprint $table): void {
                foreach ([
                    'dividends_employee_id_date_index',
                    'dividends_status_employee_id_date_index',
                ] as $index) {
                    try {
                        $table->dropIndex($index);
                    } catch (\Throwable) {
                        // Index may already be gone.
                    }
                }
            });

            SchemaForeignKeys::dropColumnIfExists('dividends', 'employee_id');
        }

        $this->ensureShareholderIndex();
    }

    public function down(): void
    {
        if (! Schema::hasTable('dividends')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteTableDown();

            return;
        }

        Schema::table('dividends', function (Blueprint $table): void {
            try {
                $table->dropIndex('dividends_shareholder_id_date_index');
            } catch (\Throwable) {
                // Index may already be gone.
            }
        });

        if (! Schema::hasColumn('dividends', 'employee_id')) {
            Schema::table('dividends', function (Blueprint $table): void {
                $table->foreignId('employee_id')
                    ->nullable()
                    ->after('id');
            });

            SchemaForeignKeys::ensure(
                'dividends',
                'employee_id',
                'employees',
                'dividends_employee_id_fk',
            );
        }

        SchemaForeignKeys::dropColumnIfExists('dividends', 'shareholder_id');
    }

    private function ensureShareholderIndex(): void
    {
        if (! Schema::hasColumn('dividends', 'shareholder_id')) {
            return;
        }

        $hasIndex = collect(Schema::getIndexes('dividends'))
            ->contains(fn (array $index): bool => ($index['columns'] ?? []) === ['shareholder_id', 'date']);

        if ($hasIndex) {
            return;
        }

        Schema::table('dividends', function (Blueprint $table): void {
            $table->index(['shareholder_id', 'date']);
        });
    }

    private function rebuildSqliteTable(): void
    {
        if (! Schema::hasColumn('dividends', 'employee_id')) {
            $this->ensureShareholderIndex();

            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::dropIfExists('dividends_tmp');

        Schema::create('dividends_tmp', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shareholder_id')->nullable()->constrained('shareholders')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('gpm_amount', 12, 2)->nullable();
            $table->decimal('net_amount', 12, 2)->nullable();
            $table->string('status', 40)->default('open');
            $table->string('comment')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('dividend_payment_report_id')->nullable()->constrained('dividend_payment_reports')->nullOnDelete();
            $table->timestamps();

            $table->index(['shareholder_id', 'date']);
            $table->index(['status', 'shareholder_id', 'date']);
        });

        foreach (DB::table('dividends')->orderBy('id')->get() as $row) {
            DB::table('dividends_tmp')->insert([
                'id' => $row->id,
                'shareholder_id' => $row->shareholder_id,
                'date' => $row->date,
                'amount' => $row->amount,
                'gpm_amount' => $row->gpm_amount,
                'net_amount' => $row->net_amount,
                'status' => $row->status,
                'comment' => $row->comment,
                'is_paid' => $row->is_paid,
                'paid_at' => $row->paid_at,
                'dividend_payment_report_id' => $row->dividend_payment_report_id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('dividends');
        Schema::rename('dividends_tmp', 'dividends');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    private function rebuildSqliteTableDown(): void
    {
        if (Schema::hasColumn('dividends', 'employee_id')) {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::dropIfExists('dividends_tmp');

        Schema::create('dividends_tmp', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('gpm_amount', 12, 2)->nullable();
            $table->decimal('net_amount', 12, 2)->nullable();
            $table->string('status', 40)->default('open');
            $table->string('comment')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('dividend_payment_report_id')->nullable()->constrained('dividend_payment_reports')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'date']);
            $table->index(['status', 'employee_id', 'date']);
        });

        foreach (DB::table('dividends')->orderBy('id')->get() as $row) {
            DB::table('dividends_tmp')->insert([
                'id' => $row->id,
                'employee_id' => null,
                'date' => $row->date,
                'amount' => $row->amount,
                'gpm_amount' => $row->gpm_amount,
                'net_amount' => $row->net_amount,
                'status' => $row->status,
                'comment' => $row->comment,
                'is_paid' => $row->is_paid,
                'paid_at' => $row->paid_at,
                'dividend_payment_report_id' => $row->dividend_payment_report_id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('dividends');
        Schema::rename('dividends_tmp', 'dividends');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
