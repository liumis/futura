<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dividends', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('dividends', function (Blueprint $table) {
            if (Schema::hasIndex('dividends', 'dividends_user_id_date_index')) {
                $table->dropIndex('dividends_user_id_date_index');
            }

            $table->dropColumn('user_id');
        });

        Schema::table('dividends', function (Blueprint $table) {
            $table->foreignId('employee_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();

            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('dividends', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('dividends', function (Blueprint $table) {
            if (Schema::hasIndex('dividends', 'dividends_employee_id_date_index')) {
                $table->dropIndex('dividends_employee_id_date_index');
            }

            $table->dropColumn('employee_id');
        });

        Schema::table('dividends', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();

            $table->index(['user_id', 'date']);
        });
    }
};
