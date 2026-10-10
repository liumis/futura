<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('document_watchers');

        Schema::create('document_watchers', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['document_id', 'user_id']);
            $table->index('user_id');

            if (Schema::hasTable('documents')) {
                $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_watchers');
    }
};
