<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('income_type_id')
                ->nullable()
                ->after('sum_inc_vat')
                ->constrained('income_types')
                ->nullOnDelete();

            $table->foreignId('expense_type_id')
                ->nullable()
                ->after('income_type_id')
                ->constrained('expense_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_type_id');
            $table->dropConstrainedForeignId('income_type_id');
        });
    }
};
