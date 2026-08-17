<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('customer_level_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['customer_level_id', 'collection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_level_prices');
        Schema::dropIfExists('customer_levels');
    }
};
