<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'notes')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('amount');
            });
        }

        if (Schema::hasTable('cargo_items') && ! Schema::hasColumn('cargo_items', 'notes')) {
            Schema::table('cargo_items', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'notes')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }

        if (Schema::hasTable('cargo_items') && Schema::hasColumn('cargo_items', 'notes')) {
            Schema::table('cargo_items', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }
    }
};
