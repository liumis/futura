<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employee_contracts', 'default_bonus')) {
            return;
        }

        Schema::table('employee_contracts', function (Blueprint $table): void {
            $table->decimal('default_bonus', 12, 2)
                ->nullable()
                ->after('base_salary');
        });
    }

    public function down(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table): void {
            $table->dropColumn('default_bonus');
        });
    }
};

