<?php

use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dividends', 'user_id')) {
            SchemaForeignKeys::dropOnColumn('dividends', 'user_id');

            Schema::table('dividends', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
        }

        if (! Schema::hasColumn('dividends', 'employee_id')) {
            Schema::table('dividends', function (Blueprint $table) {
                $table->foreignId('employee_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->index(['employee_id', 'date']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('dividends', 'employee_id')) {
            SchemaForeignKeys::dropOnColumn('dividends', 'employee_id');

            Schema::table('dividends', function (Blueprint $table) {
                $table->dropColumn('employee_id');
            });
        }

        if (! Schema::hasColumn('dividends', 'user_id')) {
            Schema::table('dividends', function (Blueprint $table) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->index(['user_id', 'date']);
            });
        }
    }
};
