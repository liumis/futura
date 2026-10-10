<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_transaction_invoice', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_statement_transaction_id')
                ->constrained('bank_statement_transactions')
                ->cascadeOnDelete();
            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(
                ['bank_statement_transaction_id', 'invoice_id'],
                'bst_invoice_unique',
            );
            $table->index('invoice_id');
        });

        // Backfill existing 1:1 links so unlink can reverse allocated amounts.
        $rows = DB::table('bank_statement_transactions')
            ->whereNotNull('invoice_id')
            ->select(['id', 'invoice_id', 'amount'])
            ->get();

        $now = now();
        foreach ($rows as $row) {
            DB::table('bank_statement_transaction_invoice')->insertOrIgnore([
                'bank_statement_transaction_id' => $row->id,
                'invoice_id' => $row->invoice_id,
                'amount' => round((float) $row->amount, 2),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_transaction_invoice');
    }
};
