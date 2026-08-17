<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('todo_watchers');

        Schema::create('todo_watchers', function (Blueprint $table) {
            $table->unsignedBigInteger('todo_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['todo_id', 'user_id']);
            $table->index('user_id');

            // todos is created in a later migration (184111); MySQL cannot add this FK yet.
            if (Schema::hasTable('todos')) {
                $table->foreign('todo_id')->references('id')->on('todos')->cascadeOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('todo_watchers');
    }
};
