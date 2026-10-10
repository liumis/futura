<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_types')) {
            Schema::create('project_types', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('projects') || Schema::hasColumn('projects', 'project_type_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignId('project_type_id')
                ->nullable()
                ->after('code')
                ->constrained('project_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('projects') && Schema::hasColumn('projects', 'project_type_id')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('project_type_id');
            });
        }

        Schema::dropIfExists('project_types');
    }
};
