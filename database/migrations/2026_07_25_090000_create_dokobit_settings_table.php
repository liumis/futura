<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dokobit_settings', function (Blueprint $table) {
            $table->id();
            $table->string('active_environment')->default('live');
            $table->text('live_access_token')->nullable();
            $table->string('live_api_url')->nullable();
            $table->text('prod_access_token')->nullable();
            $table->string('prod_api_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokobit_settings');
    }
};
