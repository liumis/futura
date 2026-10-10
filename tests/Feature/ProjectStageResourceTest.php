<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Projects\Pages\CreateProject;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\Pages\ListProjects;
use App\Filament\Admin\Resources\ProjectStages\Pages\CreateProjectStage;
use App\Filament\Admin\Resources\ProjectStages\Pages\ListProjectStages;
use App\Filament\Admin\Resources\ProjectStages\ProjectStageResource;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\ProjectType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectStageResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_project_stages_list(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ListProjectStages::class)
            ->assertOk()
            ->assertSee('Project stages');

        $this->assertNotNull(ProjectStageResource::getUrl('index'));
    }

    public function test_admin_can_create_a_project_stage_for_a_type(): void
    {
        $this->actingAsAdmin();

        $projectType = ProjectType::query()->create([
            'name' => 'Client work',
        ]);

        Livewire::test(CreateProjectStage::class)
            ->fillForm([
                'project_type_id' => $projectType->id,
                'name' => 'Sampling',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('project_stages', [
            'project_type_id' => $projectType->id,
            'name' => 'Sampling',
        ]);
    }

    public function test_admin_can_create_a_project_with_a_stage_of_its_type(): void
    {
        $this->actingAsAdmin();

        $projectType = ProjectType::query()->create([
            'name' => 'Client work',
        ]);
        $stage = ProjectStage::query()->create([
            'project_type_id' => $projectType->id,
            'name' => 'Production',
        ]);

        Livewire::test(CreateProject::class)
            ->assertFormFieldExists('project_stage_id')
            ->fillForm([
                'name' => 'Showroom',
                'code' => 'SHOW-2',
                'project_type_id' => $projectType->id,
                'project_stage_id' => $stage->id,
                'archived' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', [
            'name' => 'Showroom',
            'code' => 'SHOW-2',
            'project_type_id' => $projectType->id,
            'project_stage_id' => $stage->id,
        ]);
    }

    public function test_changing_project_type_clears_the_stage(): void
    {
        $this->actingAsAdmin();

        $clientType = ProjectType::query()->create(['name' => 'Client work']);
        $internalType = ProjectType::query()->create(['name' => 'Internal']);
        $clientStage = ProjectStage::query()->create([
            'project_type_id' => $clientType->id,
            'name' => 'Sampling',
        ]);
        $project = Project::query()->create([
            'name' => 'Website',
            'code' => 'WEB-2',
            'project_type_id' => $clientType->id,
            'project_stage_id' => $clientStage->id,
            'archived' => false,
        ]);

        Livewire::test(EditProject::class, ['record' => $project->getKey()])
            ->fillForm([
                'project_type_id' => $internalType->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $project = $project->fresh();

        $this->assertSame($internalType->id, $project->project_type_id);
        $this->assertNull($project->project_stage_id);

        Livewire::test(ListProjects::class)
            ->assertOk()
            ->assertSee('Internal');
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-project-stages@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
