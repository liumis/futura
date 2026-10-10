<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_offset_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('operation_ref', 64)->nullable();
            $table->date('operation_date')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(
                ['purchase_invoice_id', 'sales_invoice_id', 'operation_ref'],
                'invoice_offset_links_unique',
            );
            $table->index('sales_invoice_id');
            $table->index('operation_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_offset_links');
    }
};
