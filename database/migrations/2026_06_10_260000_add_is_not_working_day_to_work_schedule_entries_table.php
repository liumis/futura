<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedule_entries', function (Blueprint $table) {
            $table->boolean('is_not_working_day')->default(false)->after('hours');
        });
    }

    public function down(): void
    {
        Schema::table('work_schedule_entries', function (Blueprint $table) {
            $table->dropColumn('is_not_working_day');
        });
    }
};
