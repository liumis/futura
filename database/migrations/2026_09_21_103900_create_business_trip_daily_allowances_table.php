<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_trip_daily_allowances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('list_number')->nullable()->index();
            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->string('country');
            $table->decimal('daily_rate', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_trip_daily_allowances');
    }
};
