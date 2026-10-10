<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('currency_rate', 18, 6)->nullable()->after('currency');
            $table->decimal('sum_inc_vat_eur', 12, 2)->nullable()->after('currency_rate');
        });

        // EUR invoices: rate 1, EUR total = original total.
        DB::table('invoices')
            ->where(function ($query): void {
                $query->whereNull('currency')
                    ->orWhere('currency', '')
                    ->orWhereRaw("UPPER(currency) = 'EUR'");
            })
            ->update([
                'currency' => 'EUR',
                'currency_rate' => 1,
                'sum_inc_vat_eur' => DB::raw('sum_inc_vat'),
            ]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['currency_rate', 'sum_inc_vat_eur']);
        });
    }
};
