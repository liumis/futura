<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_schedule_entries', function (Blueprint $table) {
            $table->decimal('actual_hours', 5, 2)->default(0)->after('hours');
        });

        DB::table('work_schedule_entries')->update([
            'actual_hours' => DB::raw('hours'),
        ]);
    }

    public function down(): void
    {
        Schema::table('work_schedule_entries', function (Blueprint $table) {
            $table->dropColumn('actual_hours');
        });
    }
};
