<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->restrictOnDelete();
            $table->string('color_code');
            $table->string('color_name');
            $table->unsignedInteger('current_amount')->default(0);
            $table->timestamps();

            $table->index('collection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
