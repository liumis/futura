<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_settings', 'company_iban')
            || ! Schema::hasColumn('company_settings', 'company_bic')) {
            Schema::table('company_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('company_settings', 'company_iban')) {
                    $table->string('company_iban', 34)->nullable()->after('company_phone');
                }
                if (! Schema::hasColumn('company_settings', 'company_bic')) {
                    $table->string('company_bic', 11)->nullable()->after('company_iban');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn(['company_iban', 'company_bic']);
        });
    }
};
