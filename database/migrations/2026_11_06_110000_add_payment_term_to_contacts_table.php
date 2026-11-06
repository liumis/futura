<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contacts') || Schema::hasColumn('contacts', 'payment_term')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->unsignedInteger('payment_term')->nullable()->after('default_finance_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('contacts') || ! Schema::hasColumn('contacts', 'payment_term')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('payment_term');
        });
    }
};
