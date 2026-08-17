<?php

use App\Support\LtOfficialHolidays;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('lt_holidays', 'recurrence_key')) {
            return;
        }

        Schema::dropIfExists('lt_holidays');

        Schema::create('lt_holidays', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            $table->unsignedTinyInteger('easter_offset')->nullable();
            $table->string('recurrence_key')->unique();
            $table->timestamps();
        });

        LtOfficialHolidays::seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('lt_holidays');

        Schema::create('lt_holidays', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->timestamps();
        });
    }
};
