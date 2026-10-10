<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('directory_contacts')) {
            Schema::create('directory_contacts', function (Blueprint $table): void {
                $table->id();
                $table->string('company_name');
                $table->string('country')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->text('comment')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('directory_contact_persons')) {
            return;
        }

        Schema::create('directory_contact_persons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('directory_contact_id')
                ->constrained('directory_contacts')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('surname');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_contact_persons');
        Schema::dropIfExists('directory_contacts');
    }
};
