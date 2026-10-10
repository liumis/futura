<?php

namespace App\Filament\Admin\Pages;

use App\Enums\TodoStatus;
use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\ProjectType;
use App\Models\Todo;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class ProjectsBoard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Projects board';

    protected static ?string $title = 'Projects board';

    protected static ?string $slug = 'projects-board';

    protected static string|\UnitEnum|null $navigationGroup = 'Tasks';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.admin.pages.projects-board';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var list<string>
     */
    private const STAGE_COLORS = [
        '#2563eb',
        '#059669',
        '#d97706',
        '#7c3aed',
        '#db2777',
        '#0891b2',
        '#4f46e5',
        '#ca8a04',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'project_type_id' => ProjectType::query()->orderBy('name')->value('id'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('project_type_id')
                    ->label('Project type')
                    ->options(fn (): array => ProjectType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->placeholder('Select a project type'),
            ]);
    }

    public function selectedTypeId(): ?int
    {
        $typeId = $this->data['project_type_id'] ?? null;

        return filled($typeId) ? (int) $typeId : null;
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    public function getKanbanColumns(): array
    {
        $typeId = $this->selectedTypeId();

        if ($typeId === null) {
            return [];
        }

        $columns = [
            [
                'value' => 'unassigned',
                'label' => 'Unassigned',
                'color' => '#9ca3af',
            ],
        ];

        ProjectStage::query()
            ->where('project_type_id', $typeId)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->values()
            ->each(function (ProjectStage $stage, int $index) use (&$columns): void {
                $columns[] = [
                    'value' => (string) $stage->getKey(),
                    'label' => $stage->name,
                    'color' => self::STAGE_COLORS[$index % count(self::STAGE_COLORS)],
                ];
            });

        return $columns;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getKanbanItems(): array
    {
        $typeId = $this->selectedTypeId();

        if ($typeId === null) {
            return [];
        }

        $projects = Project::query()
            ->active()
            ->where('project_type_id', $typeId)
            ->orderBy('name')
            ->get();

        $countsByProject = $this->taskCountsByProject($projects->modelKeys());

        return $projects
            ->map(fn (Project $project): array => $this->mapKanbanItem(
                $project,
                $countsByProject[(int) $project->getKey()] ?? [],
            ))
            ->values()
            ->all();
    }

    /**
     * @return array{ok: bool, items?: array<int, array<string, mixed>>, message?: string}
     */
    public function moveProjectStage(int $projectId, string $stageKey): array
    {
        $typeId = $this->selectedTypeId();
        $project = Project::query()->find($projectId);

        if ($project === null) {
            return ['ok' => false, 'message' => 'Project not found.'];
        }

        if ($typeId === null || (int) $project->project_type_id !== $typeId) {
            return ['ok' => false, 'message' => 'Project does not belong to this type.'];
        }

        $stageId = $this->resolveStageId($stageKey, $typeId);

        if ($stageKey !== 'unassigned' && $stageId === null) {
            return ['ok' => false, 'message' => 'Invalid stage.'];
        }

        $project->update([
            'project_stage_id' => $stageId,
        ]);

        return [
            'ok' => true,
            'items' => $this->getKanbanItems(),
        ];
    }

    /**
     * @param  array<string, int>  $countsByStatus
     * @return array<string, mixed>
     */
    protected function mapKanbanItem(Project $project, array $countsByStatus): array
    {
        $taskCounts = [];

        foreach (TodoStatus::cases() as $status) {
            $count = (int) ($countsByStatus[$status->value] ?? 0);

            if ($count <= 0) {
                continue;
            }

            $taskCounts[] = [
                'label' => $status->getLabel() ?? $status->value,
                'count' => $count,
            ];
        }

        return [
            'id' => $project->getKey(),
            'title' => $project->name,
            'code' => $project->code,
            'stage' => $project->project_stage_id
                ? (string) $project->project_stage_id
                : 'unassigned',
            'task_counts' => $taskCounts,
            'task_summary' => collect($taskCounts)
                ->map(fn (array $row): string => $row['label'].' - '.$row['count'])
                ->implode(', '),
            'edit_url' => ProjectResource::getUrl('edit', ['record' => $project]),
        ];
    }

    /**
     * @param  list<int|string>  $projectIds
     * @return array<int, array<string, int>>
     */
    protected function taskCountsByProject(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = Todo::query()
            ->notArchived()
            ->whereIn('project_id', $projectIds)
            ->toBase()
            ->selectRaw('project_id, status, COUNT(*) as aggregate')
            ->groupBy('project_id', 'status')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            $status = (string) $row->status;
            $counts[$projectId][$status] = (int) $row->aggregate;
        }

        return $counts;
    }

    protected function resolveStageId(string $stageKey, int $typeId): ?int
    {
        if ($stageKey === 'unassigned' || $stageKey === '') {
            return null;
        }

        $stageId = (int) $stageKey;

        if ($stageId <= 0) {
            return null;
        }

        $exists = ProjectStage::query()
            ->whereKey($stageId)
            ->where('project_type_id', $typeId)
            ->exists();

        return $exists ? $stageId : null;
    }
}
