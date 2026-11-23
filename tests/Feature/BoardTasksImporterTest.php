<?php

namespace Tests\Feature;

use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Todo;
use App\Models\User;
use App\Services\BoardTasksImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoardTasksImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_board_tasks_for_deividas_on_default_project(): void
    {
        $deividas = User::factory()->create([
            'name' => 'Deividas',
            'email' => 'info@futuratextiles.eu',
        ]);

        $type = ProjectType::query()->create(['name' => 'Sales']);
        $project = Project::query()->create([
            'name' => 'Pardavimai',
            'code' => 'P',
            'project_type_id' => $type->id,
            'archived' => false,
        ]);

        $payload = json_decode(
            (string) file_get_contents(database_path('data/deividas-board-tasks-2026-11-23.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $first = app(BoardTasksImporter::class)->importSnapshot($payload);
        $second = app(BoardTasksImporter::class)->importSnapshot($payload);

        $this->assertSame(7, $first['created']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(7, $second['skipped']);
        $this->assertSame($deividas->id, $first['user_id']);
        $this->assertSame($project->id, $first['project_id']);

        $this->assertSame(7, Todo::query()->count());

        $high = Todo::query()->where('title', 'WEB/PANTONE')->first();
        $this->assertNotNull($high);
        $this->assertSame($deividas->id, $high->user_id);
        $this->assertSame($deividas->id, $high->responsible_id);
        $this->assertSame($project->id, $high->project_id);
        $this->assertSame(TodoStatus::New, $high->status);
        $this->assertSame(TodoPriority::High, $high->priority);

        $regular = Todo::query()->where('title', 'MORBERN CLAIM')->first();
        $this->assertNotNull($regular);
        $this->assertSame(TodoPriority::Regular, $regular->priority);
    }

    public function test_creates_default_project_when_missing(): void
    {
        User::factory()->create([
            'name' => 'Deividas',
            'email' => 'info@futuratextiles.eu',
        ]);

        $this->assertNull(Project::defaultId());

        $result = app(BoardTasksImporter::class)->importSnapshot([
            'assignee' => [
                'name' => 'Deividas',
                'email' => 'info@futuratextiles.eu',
            ],
            'project' => [
                'name' => 'Pardavimai',
                'code' => 'P',
            ],
            'tasks' => [
                ['title' => 'STOCKAI OEM', 'priority' => 'regular'],
            ],
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertNotNull(Project::defaultId());
        $this->assertDatabaseHas('projects', [
            'name' => 'Pardavimai',
            'code' => 'P',
            'archived' => false,
        ]);
    }
}
