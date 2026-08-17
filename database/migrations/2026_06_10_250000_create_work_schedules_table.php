<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->timestamps();

            $table->unique(['employee_id', 'year', 'month']);
        });

        Schema::create('work_schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['work_schedule_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_entries');
        Schema::dropIfExists('work_schedules');
    }
};
