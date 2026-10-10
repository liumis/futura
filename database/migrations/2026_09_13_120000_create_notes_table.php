<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notes')) {
            return;
        }

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('attachments')->nullable();
            $table->string('sharepoint_web_url')->nullable();
            $table->string('sharepoint_item_id')->nullable();
            $table->string('sharepoint_path')->nullable();
            $table->json('sharepoint_files')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
