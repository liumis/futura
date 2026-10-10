<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('directory_contact_stages')) {
            Schema::create('directory_contact_stages', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('directory_contacts') || Schema::hasColumn('directory_contacts', 'directory_contact_stage_id')) {
            return;
        }

        Schema::table('directory_contacts', function (Blueprint $table): void {
            $table->foreignId('directory_contact_stage_id')
                ->nullable()
                ->after('phone')
                ->constrained('directory_contact_stages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('directory_contacts') && Schema::hasColumn('directory_contacts', 'directory_contact_stage_id')) {
            Schema::table('directory_contacts', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('directory_contact_stage_id');
            });
        }

        Schema::dropIfExists('directory_contact_stages');
    }
};
