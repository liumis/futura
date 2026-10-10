<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_children', function (Blueprint $table): void {
            $table->boolean('has_disability')->default(false)->after('birthdate');
        });
    }

    public function down(): void
    {
        Schema::table('employee_children', function (Blueprint $table): void {
            $table->dropColumn('has_disability');
        });
    }
};
