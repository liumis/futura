<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'default_cost')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('default_cost');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'default_cost')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->decimal('default_cost', 12, 2)->default(0)->after('dsv_code');
            });
        }
    }
};
