<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_imports', function (Blueprint $table) {
            $table->decimal('base_cost', 12, 2)
                ->default(0)
                ->after('cost');

            $table->decimal('overhead_cost', 12, 4)
                ->default(0)
                ->after('base_cost');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_imports', function (Blueprint $table) {
            $table->dropColumn(['base_cost', 'overhead_cost']);
        });
    }
};
