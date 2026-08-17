<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('invoice_path')->nullable();
            $table->string('note')->nullable();
            $table->date('imported_at')->nullable();
            $table->timestamps();

            $table->index('imported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_imports');
    }
};
