<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('document_expiration_notices');

        Schema::create('document_expiration_notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('stage'); // two_weeks | tomorrow

            $table->timestamp('notified_at')->useCurrent();

            $table->unique(['document_id', 'stage']);
            $table->index('stage');

            if (Schema::hasTable('documents')) {
                $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_expiration_notices');
    }
};
