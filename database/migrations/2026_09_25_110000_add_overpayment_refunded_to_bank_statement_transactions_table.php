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

        if (Schema::hasColumn('bank_statement_transactions', 'overpayment_refunded')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            $table->boolean('overpayment_refunded')
                ->default(false)
                ->after('is_refund');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        if (! Schema::hasColumn('bank_statement_transactions', 'overpayment_refunded')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            $table->dropColumn('overpayment_refunded');
        });
    }
};
