<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('birthdate');
            $table->timestamps();

            $table->index(['employee_id', 'birthdate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_children');
    }
};
