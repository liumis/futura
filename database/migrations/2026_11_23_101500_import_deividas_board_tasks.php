<?php

use App\Services\BoardTasksImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Import board tasks (UŽDUOTYS) for Deividas on default project Pardavimai (P).
 * Snapshot: database/data/deividas-board-tasks-2026-11-23.json
 * Idempotent by title + user_id + project_id (non-archived).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('todos')) {
            return;
        }

        $path = database_path('data/deividas-board-tasks-2026-11-23.json');
        if (! is_file($path)) {
            return;
        }

        /** @var array{assignee?: array{name?: string, email?: string}, project?: array{name?: string, code?: string}, tasks?: list<array{title?: string, priority?: string}>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        app(BoardTasksImporter::class)->importSnapshot($payload);
    }

    public function down(): void
    {
        // Keep imported tasks; not safely reversible.
    }
};
