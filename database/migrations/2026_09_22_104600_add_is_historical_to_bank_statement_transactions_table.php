<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('bank_statement_transactions', 'is_historical')) {
                $table->boolean('is_historical')
                    ->default(false)
                    ->after('no_document_needed');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('bank_statement_transactions', 'is_historical')) {
                $table->dropColumn('is_historical');
            }
        });
    }
};
