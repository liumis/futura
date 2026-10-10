<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'currency')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->string('currency', 3)->default('EUR')->after('sum_inc_vat');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'currency')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn('currency');
            });
        }
    }
};
