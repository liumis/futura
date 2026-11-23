<?php

namespace Tests\Feature;

use App\Enums\TodoStatus;
use App\Filament\Admin\Pages\Dashboard;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Todo;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardCreateTaskActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_can_create_task_from_quick_modal(): void
    {
        Role::findOrCreate(User::ROLE_ADMIN);
        $admin = User::factory()->create(['email' => 'admin-dash-task@example.com']);
        $admin->assignRole(User::ROLE_ADMIN);

        $type = ProjectType::query()->create(['name' => 'Sales']);
        Project::query()->create([
            'name' => 'web',
            'code' => 'w',
            'project_type_id' => $type->id,
            'archived' => false,
        ]);
        $project = Project::query()->create([
            'name' => 'Pardavimai',
            'code' => 'P',
            'project_type_id' => $type->id,
            'archived' => false,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $this->assertSame($project->id, Project::defaultId());

        Livewire::test(Dashboard::class)
            ->mountAction('createTask')
            ->assertSchemaStateSet([
                'project_id' => $project->getKey(),
            ])
            ->fillForm([
                'title' => 'Quick dash task',
                'project_id' => $project->getKey(),
                'deadline' => now()->addDays(3)->toDateString(),
                'responsible_id' => $admin->getKey(),
                'description' => 'From dashboard modal',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('todos', [
            'title' => 'Quick dash task',
            'project_id' => $project->getKey(),
            'user_id' => $admin->getKey(),
            'responsible_id' => $admin->getKey(),
            'status' => TodoStatus::New->value,
            'archived' => false,
        ]);

        $todo = Todo::query()->where('title', 'Quick dash task')->first();
        $this->assertNotNull($todo);
        $this->assertSame('From dashboard modal', $todo->description);
    }

    public function test_dashboard_can_edit_task_from_quick_modal(): void
    {
        Role::findOrCreate(User::ROLE_ADMIN);
        $admin = User::factory()->create(['email' => 'admin-dash-task-edit@example.com']);
        $admin->assignRole(User::ROLE_ADMIN);

        $type = ProjectType::query()->create(['name' => 'Sales edit']);
        $project = Project::query()->create([
            'name' => 'Pardavimai',
            'code' => 'PARD-2',
            'project_type_id' => $type->id,
            'archived' => false,
        ]);
        $otherProject = Project::query()->create([
            'name' => 'Marketing',
            'code' => 'MKT-1',
            'project_type_id' => $type->id,
            'archived' => false,
        ]);

        $todo = Todo::query()->create([
            'title' => 'TESTS1',
            'description' => 'Old note',
            'project_id' => $project->id,
            'user_id' => $admin->id,
            'responsible_id' => $admin->id,
            'deadline' => now()->addDays(2)->toDateString(),
            'status' => TodoStatus::New,
            'archived' => false,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(Dashboard::class)
            ->callAction('editTask', data: [
                'title' => 'TESTS1 updated',
                'project_id' => $otherProject->getKey(),
                'deadline' => now()->addDays(5)->toDateString(),
                'responsible_id' => $admin->getKey(),
                'description' => 'Updated from dashboard',
            ], arguments: [
                'todoId' => $todo->getKey(),
            ])
            ->assertHasNoActionErrors();

        $todo->refresh();
        $this->assertSame('TESTS1 updated', $todo->title);
        $this->assertSame($otherProject->id, $todo->project_id);
        $this->assertSame('Updated from dashboard', $todo->description);
    }

    public function test_dashboard_edit_modal_can_step_status_forward(): void
    {
        Role::findOrCreate(User::ROLE_ADMIN);
        $admin = User::factory()->create(['email' => 'admin-dash-task-status@example.com']);
        $admin->assignRole(User::ROLE_ADMIN);

        $type = ProjectType::query()->create(['name' => 'Sales status']);
        $project = Project::query()->create([
            'name' => 'Pardavimai',
            'code' => 'P',
            'project_type_id' => $type->id,
            'archived' => false,
        ]);

        $todo = Todo::query()->create([
            'title' => 'Status step task',
            'project_id' => $project->id,
            'user_id' => $admin->id,
            'responsible_id' => $admin->id,
            'deadline' => now()->addDays(2)->toDateString(),
            'status' => TodoStatus::New,
            'archived' => false,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(Dashboard::class)
            ->mountAction('editTask', arguments: ['todoId' => $todo->getKey()])
            ->callAction(
                TestAction::make('quickStatusForward')->schemaComponent('quickStatus'),
            )
            ->assertHasNoActionErrors();

        $this->assertSame(TodoStatus::InProgress, $todo->fresh()->status);
    }
}
