<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_trip_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_trip_daily_allowance_id')->constrained('business_trip_daily_allowances')->restrictOnDelete();
            $table->string('country_name');
            $table->decimal('daily_rate', 8, 2);
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('first_day_hours', 5, 2)->nullable();
            $table->decimal('last_day_hours', 5, 2)->nullable();
            $table->unsignedSmallInteger('calendar_days')->default(0);
            $table->decimal('payable_units', 8, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('purpose')->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 32)->default('new')->index();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('payment_status', 32)->default('unpaid')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_trip_requests');
    }
};
