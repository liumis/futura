<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('overtime_requests', 'hours')) {
            return;
        }

        Schema::table('overtime_requests', function (Blueprint $table): void {
            $table->decimal('hours', 8, 2)->default(0)->after('date_to');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table): void {
            $table->dropColumn('hours');
        });
    }
};
