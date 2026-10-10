<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_types', function (Blueprint $table) {
            $table->foreignId('invoice_code_id')
                ->nullable()
                ->after('name')
                ->constrained('invoice_codes')
                ->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('gl_generated_type', 32)->nullable()->after('is_own_account');
            $table->string('gl_generated_key', 64)->nullable()->after('gl_generated_type');
            $table->index(['gl_generated_type', 'gl_generated_key']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['gl_generated_type', 'gl_generated_key']);
            $table->dropColumn(['gl_generated_type', 'gl_generated_key']);
        });

        Schema::table('expense_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_code_id');
        });
    }
};
