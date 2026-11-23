<?php

namespace App\Services;

use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class BoardTasksImporter
{
    /**
     * @param  array{
     *     assignee?: array{name?: string, email?: string},
     *     project?: array{name?: string, code?: string},
     *     tasks?: list<array{title?: string, priority?: string}>
     * }  $payload
     * @return array{created: int, skipped: int, user_id: int|null, project_id: int|null}
     */
    public function importSnapshot(array $payload): array
    {
        if (! Schema::hasTable('todos') || ! Schema::hasTable('users') || ! Schema::hasTable('projects')) {
            return ['created' => 0, 'skipped' => 0, 'user_id' => null, 'project_id' => null];
        }

        $tasks = $payload['tasks'] ?? [];
        if ($tasks === []) {
            return ['created' => 0, 'skipped' => 0, 'user_id' => null, 'project_id' => null];
        }

        $user = $this->resolveAssignee($payload['assignee'] ?? []);
        if ($user === null) {
            return ['created' => 0, 'skipped' => count($tasks), 'user_id' => null, 'project_id' => null];
        }

        $project = $this->resolveDefaultProject($payload['project'] ?? []);
        if ($project === null) {
            return ['created' => 0, 'skipped' => count($tasks), 'user_id' => (int) $user->id, 'project_id' => null];
        }

        $created = 0;
        $skipped = 0;

        foreach ($tasks as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                $skipped++;

                continue;
            }

            $exists = Todo::query()
                ->where('title', $title)
                ->where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->where('archived', false)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $priority = TodoPriority::tryFrom((string) ($row['priority'] ?? '')) ?? TodoPriority::Regular;

            Todo::query()->create([
                'title' => $title,
                'user_id' => $user->id,
                'responsible_id' => $user->id,
                'project_id' => $project->id,
                'status' => TodoStatus::New,
                'priority' => $priority,
                'archived' => false,
                'deadline' => now()->addDay()->setTime(18, 0),
            ]);

            $created++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'user_id' => (int) $user->id,
            'project_id' => (int) $project->id,
        ];
    }

    /**
     * @param  array{name?: string, email?: string}  $assignee
     */
    private function resolveAssignee(array $assignee): ?User
    {
        $email = trim((string) ($assignee['email'] ?? ''));
        if ($email !== '') {
            $byEmail = User::query()->where('email', $email)->first();
            if ($byEmail !== null) {
                return $byEmail;
            }
        }

        $name = trim((string) ($assignee['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return User::query()
            ->where('name', $name)
            ->orWhere('name', 'like', $name.'%')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array{name?: string, code?: string}  $project
     */
    private function resolveDefaultProject(array $project): ?Project
    {
        $existingId = Project::defaultId();
        if ($existingId !== null) {
            return Project::query()->find($existingId);
        }

        $name = trim((string) ($project['name'] ?? 'Pardavimai')) ?: 'Pardavimai';
        $code = trim((string) ($project['code'] ?? 'P')) ?: 'P';

        $found = Project::query()
            ->where(function ($query) use ($name, $code): void {
                $query->whereRaw('UPPER(code) = ?', [strtoupper($code)])
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($name)]);
            })
            ->orderBy('id')
            ->first();

        if ($found !== null) {
            return $found;
        }

        $typeId = ProjectType::query()->orderBy('id')->value('id');
        if ($typeId === null) {
            $typeId = ProjectType::query()->create(['name' => 'Sales'])->id;
        }

        return Project::query()->create([
            'name' => $name,
            'code' => $code,
            'project_type_id' => $typeId,
            'archived' => false,
        ]);
    }
}
