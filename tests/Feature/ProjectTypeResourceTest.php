<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Projects\Pages\CreateProject;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\Pages\ListProjects;
use App\Filament\Admin\Resources\ProjectTypes\Pages\CreateProjectType;
use App\Filament\Admin\Resources\ProjectTypes\Pages\ListProjectTypes;
use App\Filament\Admin\Resources\ProjectTypes\ProjectTypeResource;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectTypeResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_project_types_list(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ListProjectTypes::class)
            ->assertOk()
            ->assertSee('Project types');

        $this->assertNotNull(ProjectTypeResource::getUrl('index'));
    }

    public function test_admin_can_create_a_project_type(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateProjectType::class)
            ->fillForm([
                'name' => 'Internal',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('project_types', [
            'name' => 'Internal',
        ]);
    }

    public function test_admin_can_create_a_project_with_a_type(): void
    {
        $this->actingAsAdmin();

        $projectType = ProjectType::query()->create([
            'name' => 'Client work',
        ]);

        Livewire::test(CreateProject::class)
            ->assertFormFieldExists('project_type_id')
            ->fillForm([
                'name' => 'Showroom',
                'code' => 'SHOW-1',
                'project_type_id' => $projectType->id,
                'archived' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', [
            'name' => 'Showroom',
            'code' => 'SHOW-1',
            'project_type_id' => $projectType->id,
        ]);
    }

    public function test_admin_can_assign_a_type_when_editing_a_project(): void
    {
        $this->actingAsAdmin();

        $projectType = ProjectType::query()->create([
            'name' => 'Internal',
        ]);
        $project = Project::query()->create([
            'name' => 'Website',
            'code' => 'WEB-1',
            'archived' => false,
        ]);

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm([
                'project_type_id' => $projectType->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($projectType->id, $project->fresh()->project_type_id);

        Livewire::test(ListProjects::class)
            ->assertOk()
            ->assertSee('Internal');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-project-types@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
