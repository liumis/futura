<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'is_general_manager')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('is_general_manager')
                ->default(false)
                ->after('shareholder_percentage');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'is_general_manager')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('is_general_manager');
        });
    }
};
