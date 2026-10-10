<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'payment_term')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('payment_term')->nullable()->after('invoice_language');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'payment_term')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('payment_term');
        });
    }
};
