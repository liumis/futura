<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('overtime_requests')) {
            return;
        }

        // Already converted.
        if (Schema::hasColumn('overtime_requests', 'date')
            && ! Schema::hasColumn('overtime_requests', 'date_from')
            && ! Schema::hasColumn('overtime_requests', 'date_to')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->rebuildSqliteTable();

            return;
        }

        Schema::table('overtime_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('overtime_requests', 'date')) {
                $table->date('date')->nullable()->after('employee_id');
            }
        });

        if (Schema::hasColumn('overtime_requests', 'date_from')) {
            DB::table('overtime_requests')->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('overtime_requests')
                        ->where('id', $row->id)
                        ->update([
                            'date' => $row->date_from ?? $row->date_to ?? now()->toDateString(),
                        ]);
                }
            });
        }

        Schema::table('overtime_requests', function (Blueprint $table): void {
            try {
                $table->dropIndex(['date_from', 'date_to']);
            } catch (\Throwable) {
                // Index may already be gone.
            }

            $drop = [];
            if (Schema::hasColumn('overtime_requests', 'date_from')) {
                $drop[] = 'date_from';
            }
            if (Schema::hasColumn('overtime_requests', 'date_to')) {
                $drop[] = 'date_to';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE overtime_requests MODIFY date DATE NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE overtime_requests ALTER COLUMN date SET NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('overtime_requests') || ! Schema::hasColumn('overtime_requests', 'date')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteTableDown();

            return;
        }

        Schema::table('overtime_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('overtime_requests', 'date_from')) {
                $table->date('date_from')->nullable()->after('employee_id');
            }

            if (! Schema::hasColumn('overtime_requests', 'date_to')) {
                $table->date('date_to')->nullable()->after('date_from');
            }
        });

        DB::table('overtime_requests')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('overtime_requests')
                    ->where('id', $row->id)
                    ->update([
                        'date_from' => $row->date,
                        'date_to' => $row->date,
                    ]);
            }
        });

        Schema::table('overtime_requests', function (Blueprint $table): void {
            $table->dropColumn('date');
            $table->index(['date_from', 'date_to']);
        });
    }

    protected function rebuildSqliteTable(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::dropIfExists('overtime_requests_tmp');

        Schema::create('overtime_requests_tmp', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');
            $table->decimal('hours', 8, 2)->default(0);
            $table->unsignedBigInteger('overtime_request_type_id');
            $table->text('comment')->nullable();
            $table->string('status')->default('new');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index('status');
        });

        $hasDateFrom = Schema::hasColumn('overtime_requests', 'date_from');
        $hasDate = Schema::hasColumn('overtime_requests', 'date');
        $hasHours = Schema::hasColumn('overtime_requests', 'hours');

        $rows = DB::table('overtime_requests')->orderBy('id')->get();
        foreach ($rows as $row) {
            $date = now()->toDateString();
            if ($hasDate && filled($row->date ?? null)) {
                $date = $row->date;
            } elseif ($hasDateFrom && filled($row->date_from ?? null)) {
                $date = $row->date_from;
            } elseif ($hasDateFrom && filled($row->date_to ?? null)) {
                $date = $row->date_to;
            }

            DB::table('overtime_requests_tmp')->insert([
                'id' => $row->id,
                'employee_id' => $row->employee_id,
                'date' => $date,
                'hours' => $hasHours ? ($row->hours ?? 0) : 0,
                'overtime_request_type_id' => $row->overtime_request_type_id,
                'comment' => $row->comment,
                'status' => $row->status,
                'confirmed_by' => $row->confirmed_by,
                'confirmed_at' => $row->confirmed_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('overtime_requests');
        Schema::rename('overtime_requests_tmp', 'overtime_requests');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function rebuildSqliteTableDown(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::dropIfExists('overtime_requests_tmp');

        Schema::create('overtime_requests_tmp', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('hours', 8, 2)->default(0);
            $table->unsignedBigInteger('overtime_request_type_id');
            $table->text('comment')->nullable();
            $table->string('status')->default('new');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['date_from', 'date_to']);
            $table->index('status');
        });

        $rows = DB::table('overtime_requests')->orderBy('id')->get();
        foreach ($rows as $row) {
            DB::table('overtime_requests_tmp')->insert([
                'id' => $row->id,
                'employee_id' => $row->employee_id,
                'date_from' => $row->date,
                'date_to' => $row->date,
                'hours' => $row->hours ?? 0,
                'overtime_request_type_id' => $row->overtime_request_type_id,
                'comment' => $row->comment,
                'status' => $row->status,
                'confirmed_by' => $row->confirmed_by,
                'confirmed_at' => $row->confirmed_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('overtime_requests');
        Schema::rename('overtime_requests_tmp', 'overtime_requests');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
