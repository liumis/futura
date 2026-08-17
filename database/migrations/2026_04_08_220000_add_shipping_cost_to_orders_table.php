<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'shipping_cost')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // SQLite ignores column order; avoid ->after() which can misbehave on some drivers.
            $table->decimal('shipping_cost', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'shipping_cost')) {
                $table->dropColumn('shipping_cost');
            }
        });
    }
};
