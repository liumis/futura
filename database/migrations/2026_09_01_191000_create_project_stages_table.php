<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_stages')) {
            Schema::create('project_stages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('project_type_id')
                    ->constrained('project_types')
                    ->cascadeOnDelete();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('projects') || Schema::hasColumn('projects', 'project_stage_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('project_stage_id')
                ->nullable()
                ->after('project_type_id')
                ->constrained('project_stages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('projects') && Schema::hasColumn('projects', 'project_stage_id')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('project_stage_id');
            });
        }

        Schema::dropIfExists('project_stages');
    }
};
