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
            $table->unsignedBigInteger('bank_statement_transaction_id');
            $table->unsignedBigInteger('invoice_id');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->foreign('bank_statement_transaction_id', 'bst_invoice_tx_fk')
                ->references('id')
                ->on('bank_statement_transactions')
                ->cascadeOnDelete();
            $table->foreign('invoice_id', 'bst_invoice_inv_fk')
                ->references('id')
                ->on('invoices')
                ->cascadeOnDelete();

            $table->unique(
                ['bank_statement_transaction_id', 'invoice_id'],
                'bst_invoice_unique',
            );
            $table->index('invoice_id', 'bst_invoice_inv_idx');
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
