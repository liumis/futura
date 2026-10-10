<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'payment_term')) {
                $table->unsignedInteger('payment_term')->nullable()->after('sum_inc_vat');
            }

            if (! Schema::hasColumn('invoices', 'paid_amount')) {
                $table->decimal('paid_amount', 12, 2)->default(0)->after('payment_term');
            }

            if (! Schema::hasColumn('invoices', 'paid')) {
                $table->boolean('paid')->default(false)->after('paid_amount');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('invoices', 'payment_term') ? 'payment_term' : null,
                Schema::hasColumn('invoices', 'paid_amount') ? 'paid_amount' : null,
                Schema::hasColumn('invoices', 'paid') ? 'paid' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
