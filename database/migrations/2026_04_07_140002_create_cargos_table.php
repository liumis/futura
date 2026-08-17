<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->date('date_shipped');
            $table->date('estimated_arrival');
            $table->unsignedInteger('amount');
            $table->timestamps();

            $table->index('product_id');
            $table->index('estimated_arrival');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargos');
    }
};
