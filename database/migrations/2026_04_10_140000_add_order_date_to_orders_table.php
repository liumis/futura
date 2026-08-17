<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'order_date')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('order_date')->nullable();
        });

        DB::statement('UPDATE orders SET order_date = created_at WHERE order_date IS NULL');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'order_date')) {
                $table->dropColumn('order_date');
            }
        });
    }
};
