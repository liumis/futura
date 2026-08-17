<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->index('archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
