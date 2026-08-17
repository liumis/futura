<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documents') || ! Schema::hasColumn('documents', 'document_date')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE documents MODIFY document_date DATETIME NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE documents ALTER COLUMN document_date TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        } elseif ($driver === 'sqlite') {
            // SQLite has no strict date vs datetime distinction for this purpose.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('documents') || ! Schema::hasColumn('documents', 'document_date')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE documents MODIFY document_date DATE NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE documents ALTER COLUMN document_date TYPE DATE USING document_date::date');
        }
    }
};
