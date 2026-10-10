<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'stock_reserved')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('stock_reserved')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'stock_reserved')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('stock_reserved');
        });
    }
};
