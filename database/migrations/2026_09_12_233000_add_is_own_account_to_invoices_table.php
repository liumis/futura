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
            if (! Schema::hasColumn('invoices', 'is_own_account')) {
                $table->boolean('is_own_account')
                    ->default(false)
                    ->after('expense_type_id');
            }
        });

        DB::table('invoices')
            ->where(function ($query): void {
                $query
                    ->where('invoice_number', 'like', '%Tarp savo%')
                    ->orWhere('invoice_number', 'like', '%Between account%')
                    ->orWhere('invoice_number', 'like', '%between account%');
            })
            ->update([
                'is_own_account' => true,
                'income_type_id' => null,
                'expense_type_id' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('invoices', 'is_own_account')) {
                $table->dropColumn('is_own_account');
            }
        });
    }
};
