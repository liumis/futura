<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                if (! Schema::hasColumn('invoices', 'sharepoint_web_url')) {
                    $table->string('sharepoint_web_url', 2000)->nullable();
                }
                if (! Schema::hasColumn('invoices', 'sharepoint_item_id')) {
                    $table->string('sharepoint_item_id')->nullable();
                }
                if (! Schema::hasColumn('invoices', 'sharepoint_path')) {
                    $table->string('sharepoint_path', 1000)->nullable();
                }
            });
        }

        if (Schema::hasTable('bank_statement_transactions') && ! Schema::hasColumn('bank_statement_transactions', 'invoice_id')) {
            Schema::table('bank_statement_transactions', function (Blueprint $table): void {
                $table->foreignId('invoice_id')
                    ->nullable()
                    ->constrained('invoices')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_statement_transactions') && Schema::hasColumn('bank_statement_transactions', 'invoice_id')) {
            Schema::table('bank_statement_transactions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('invoice_id');
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                foreach (['sharepoint_web_url', 'sharepoint_item_id', 'sharepoint_path'] as $column) {
                    if (Schema::hasColumn('invoices', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
