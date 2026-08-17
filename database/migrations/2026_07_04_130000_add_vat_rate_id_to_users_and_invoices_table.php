<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('vat_rate_id')
                ->nullable()
                ->after('customer_level_id')
                ->constrained('vat_rates')
                ->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('vat_rate_id')
                ->nullable()
                ->after('expense_type_id')
                ->constrained('vat_rates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['vat_rate_id']);
            $table->dropColumn('vat_rate_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['vat_rate_id']);
            $table->dropColumn('vat_rate_id');
        });
    }
};
