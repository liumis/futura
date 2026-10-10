<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('company_bank_accounts', 'statement_aliases')) {
            return;
        }

        Schema::table('company_bank_accounts', function (Blueprint $table): void {
            $table->json('statement_aliases')->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_bank_accounts', 'statement_aliases')) {
            return;
        }

        Schema::table('company_bank_accounts', function (Blueprint $table): void {
            $table->dropColumn('statement_aliases');
        });
    }
};
