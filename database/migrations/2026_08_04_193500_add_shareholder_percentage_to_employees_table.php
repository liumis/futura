<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'shareholder_percentage')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->decimal('shareholder_percentage', 5, 2)->nullable()->after('working_time_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('shareholder_percentage');
        });
    }
};
