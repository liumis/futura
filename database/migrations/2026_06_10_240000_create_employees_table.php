<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('surname');
            $table->date('birthdate')->nullable();
            $table->string('position')->nullable();
            $table->string('bank_account')->nullable();
            $table->date('contract_signed_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->decimal('working_time_percentage', 5, 2)->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
