<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'cost')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->decimal('cost', 12, 2)->default(0)->after('dsv_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'cost')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('cost');
            });
        }
    }
};
