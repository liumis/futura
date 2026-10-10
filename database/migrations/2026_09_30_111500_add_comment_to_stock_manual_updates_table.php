<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_manual_updates') || Schema::hasColumn('stock_manual_updates', 'comment')) {
            return;
        }

        Schema::table('stock_manual_updates', function (Blueprint $table): void {
            $table->text('comment')->nullable()->after('new_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_manual_updates') || ! Schema::hasColumn('stock_manual_updates', 'comment')) {
            return;
        }

        Schema::table('stock_manual_updates', function (Blueprint $table): void {
            $table->dropColumn('comment');
        });
    }
};
