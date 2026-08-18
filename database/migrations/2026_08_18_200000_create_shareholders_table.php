<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shareholders')) {
            return;
        }

        Schema::create('shareholders', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('shareholder_percentage', 5, 2);
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('bank_account')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shareholders');
    }
};
