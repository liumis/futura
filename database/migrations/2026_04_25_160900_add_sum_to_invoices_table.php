<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('sum_without_vat', 12, 2)->default(0)->after('invoice_date');
            $table->decimal('vat', 12, 2)->default(0)->after('sum_without_vat');
            $table->decimal('sum_inc_vat', 12, 2)->default(0)->after('vat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['sum_without_vat', 'vat', 'sum_inc_vat']);
        });
    }
};
