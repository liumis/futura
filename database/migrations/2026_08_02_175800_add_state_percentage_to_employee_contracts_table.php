<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table): void {
            $table->decimal('state_percentage', 5, 2)->nullable()->after('base_salary');
        });
    }

    public function down(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table): void {
            $table->dropColumn('state_percentage');
        });
    }
};
