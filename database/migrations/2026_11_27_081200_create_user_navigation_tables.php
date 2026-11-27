<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_navigation_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 32)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'sort']);
        });

        Schema::create('user_navigation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_key');
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->string('custom_label')->nullable();
            $table->foreignId('group_id')
                ->nullable()
                ->constrained('user_navigation_groups')
                ->nullOnDelete();
            $table->boolean('is_landing')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'item_key']);
            $table->index(['user_id', 'sort']);
            $table->index(['user_id', 'is_landing']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_navigation_items');
        Schema::dropIfExists('user_navigation_groups');
    }
};
