<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('cargo_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('cost', 12, 2)->default(0);
            $table->date('received_date');
            $table->unsignedInteger('amount')->default(0);
            $table->timestamps();

            $table->unique(['cargo_id', 'product_id']);
            $table->index('received_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_imports');
    }
};
