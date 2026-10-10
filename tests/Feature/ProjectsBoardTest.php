<?php

namespace Tests\Feature;

use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use App\Filament\Admin\Pages\ProjectsBoard;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\ProjectType;
use App\Models\Todo;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectsBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_projects_board_and_load_stages_for_a_type(): void
    {
        $this->actingAsAdmin();

        $clientType = ProjectType::query()->create(['name' => 'Client work']);
        $internalType = ProjectType::query()->create(['name' => 'Internal']);
        ProjectStage::query()->create([
            'project_type_id' => $clientType->id,
            'name' => 'Sampling',
        ]);
        ProjectStage::query()->create([
            'project_type_id' => $internalType->id,
            'name' => 'Planning',
        ]);

        Livewire::test(ProjectsBoard::class)
            ->assertOk()
            ->assertSee('Projects board')
            ->fillForm([
                'project_type_id' => $clientType->id,
            ])
            ->assertSee('Sampling')
            ->assertSee('Unassigned')
            ->assertDontSee('Planning');
    }

    public function test_admin_can_drag_a_project_to_another_stage(): void
    {
        $this->actingAsAdmin();

        $type = ProjectType::query()->create(['name' => 'Client work']);
        $sampling = ProjectStage::query()->create([
            'project_type_id' => $type->id,
            'name' => 'Sampling',
        ]);
        $production = ProjectStage::query()->create([
            'project_type_id' => $type->id,
            'name' => 'Production',
        ]);
        $project = Project::query()->create([
            'name' => 'Showroom',
            'code' => 'SHOW-3',
            'project_type_id' => $type->id,
            'project_stage_id' => $sampling->id,
            'archived' => false,
        ]);

        Livewire::test(ProjectsBoard::class)
            ->fillForm([
                'project_type_id' => $type->id,
            ])
            ->call('moveProjectStage', $project->id, (string) $production->id)
            ->assertOk();

        $this->assertSame($production->id, $project->fresh()->project_stage_id);
    }

    public function test_project_cards_include_task_counts_by_status(): void
    {
        $admin = $this->actingAsAdmin();

        $type = ProjectType::query()->create(['name' => 'Client work']);
        $stage = ProjectStage::query()->create([
            'project_type_id' => $type->id,
            'name' => 'Sampling',
        ]);
        $project = Project::query()->create([
            'name' => 'Showroom',
            'code' => 'SHOW-4',
            'project_type_id' => $type->id,
            'project_stage_id' => $stage->id,
            'archived' => false,
        ]);

        Todo::withoutEvents(fn () => Todo::query()->create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'title' => 'Kickoff',
            'status' => TodoStatus::New,
            'priority' => TodoPriority::Regular,
            'deadline' => now()->addDay(),
            'archived' => false,
        ]));
        Todo::withoutEvents(fn () => Todo::query()->create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'title' => 'Finish samples',
            'status' => TodoStatus::Done,
            'priority' => TodoPriority::Regular,
            'deadline' => now()->addDay(),
            'archived' => false,
        ]));
        Todo::withoutEvents(fn () => Todo::query()->create([
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'title' => 'Archived leftover',
            'status' => TodoStatus::New,
            'priority' => TodoPriority::Regular,
            'deadline' => now()->addDay(),
            'archived' => true,
        ]));

        Livewire::test(ProjectsBoard::class)
            ->fillForm([
                'project_type_id' => $type->id,
            ])
            ->assertOk()
            ->assertSee('New - 1')
            ->assertSee('Done - 1')
            ->assertDontSee('New - 2');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-projects-board@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
