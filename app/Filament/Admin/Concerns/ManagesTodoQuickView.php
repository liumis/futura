<?php

namespace App\Filament\Admin\Concerns;

use App\Models\Project;
use App\Models\SharepointSetting;
use App\Models\Todo;
use App\Models\User;
use App\Services\DocumentBinaryStore;
use App\Services\SharepointTaskUploader;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

trait ManagesTodoQuickView
{
    /**
     * Temporary uploads for the todo quick-view modal.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $quickViewUploads = [];

    /**
     * @return array<string, mixed>
     */
    abstract protected function quickViewRefreshPayload(): array;

    /**
     * @return array{
     *     users: array<int, array{id: int, label: string}>,
     *     projects: array<int, array{id: int, label: string, archived: bool}>,
     *     current_user_id: int|null,
     *     current_user_label: string
     * }
     */
    public function getQuickViewMeta(): array
    {
        $users = User::query()
            ->orderBy('name')
            ->orderBy('email')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->getKey(),
                'label' => (string) ($user->name ?? $user->email ?? ('User #'.$user->getKey())),
            ])
            ->values()
            ->all();

        $projects = Project::query()
            ->orderBy('name')
            ->orderBy('code')
            ->get(['id', 'name', 'code', 'archived'])
            ->map(fn (Project $project): array => [
                'id' => (int) $project->getKey(),
                'label' => $project->label(),
                'archived' => (bool) $project->archived,
            ])
            ->values()
            ->all();

        return [
            'users' => $users,
            'projects' => $projects,
            'current_user_id' => auth()->id() ? (int) auth()->id() : null,
            'current_user_label' => (string) (auth()->user()?->name ?? auth()->user()?->email ?? '—'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTodoQuickView(int $todoId): ?array
    {
        $todo = Todo::query()
            ->with(['user', 'project', 'watchers:id,name,email'])
            ->find($todoId);

        if ($todo === null) {
            return null;
        }

        return $this->todoToQuickViewPayload($todo);
    }

    public function resetQuickViewUploads(): void
    {
        $this->quickViewUploads = [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool}&array<string, mixed>
     */
    public function createTodoQuickView(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            Notification::make()
                ->title('Title is required')
                ->warning()
                ->send();

            return ['ok' => false];
        }

        $userId = $this->resolveUserId($payload['user_id'] ?? null);
        if ($userId === null) {
            Notification::make()
                ->title('User is required')
                ->warning()
                ->send();

            return ['ok' => false];
        }

        $projectId = $this->resolveProjectId($payload['project_id'] ?? null);
        if ($projectId === null) {
            Notification::make()
                ->title('Project is required')
                ->warning()
                ->send();

            return ['ok' => false];
        }

        try {
            $attributes = $this->payloadToTodoAttributes($payload, $userId);
            $newUploads = $attributes['_new_uploads'] ?? [];
            unset($attributes['_new_uploads'], $attributes['_kept_sharepoint_files']);
            $todo = Todo::query()->create($attributes);
            $todo->watchers()->sync($this->resolveWatcherIds($payload['watcher_ids'] ?? []));
            $this->ingestQuickViewUploads($todo, $newUploads);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not create todo')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return ['ok' => false];
        } finally {
            $this->quickViewUploads = [];
        }

        Notification::make()
            ->title('Task created')
            ->success()
            ->send();

        return [
            'ok' => true,
            ...$this->quickViewRefreshPayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool}&array<string, mixed>
     */
    public function updateTodoQuickView(int $todoId, array $payload): array
    {
        $todo = Todo::query()->find($todoId);
        if ($todo === null) {
            Notification::make()
                ->title('Task not found')
                ->danger()
                ->send();

            return ['ok' => false];
        }

        if (! $this->canEditTodo($todo)) {
            Notification::make()
                ->title('Only task author can update this item')
                ->warning()
                ->send();

            return ['ok' => false];
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            Notification::make()
                ->title('Title is required')
                ->warning()
                ->send();

            return ['ok' => false];
        }

        $userId = $this->resolveUserId($payload['user_id'] ?? $todo->user_id);
        if ($userId === null) {
            Notification::make()
                ->title('User is required')
                ->warning()
                ->send();

            return ['ok' => false];
        }

        $projectId = $this->resolveProjectId($payload['project_id'] ?? $todo->project_id);
        if ($projectId === null) {
            Notification::make()
                ->title('Project is required')
                ->warning()
                ->send();

            return ['ok' => false];
        }

        try {
            $attributes = $this->payloadToTodoAttributes($payload, $userId, $todo);
            $newUploads = $attributes['_new_uploads'] ?? [];
            $keptSharepoint = $attributes['_kept_sharepoint_files'] ?? null;
            unset($attributes['_new_uploads'], $attributes['_kept_sharepoint_files']);
            $todo->update($attributes);
            if (is_array($keptSharepoint)) {
                SharepointTaskUploader::replaceSharepointFiles($todo->fresh(), $keptSharepoint);
            }
            $todo->watchers()->sync($this->resolveWatcherIds($payload['watcher_ids'] ?? []));
            $this->ingestQuickViewUploads($todo->fresh(), $newUploads);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not save todo')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return ['ok' => false];
        } finally {
            $this->quickViewUploads = [];
        }

        Notification::make()
            ->title('Task updated')
            ->success()
            ->send();

        return [
            'ok' => true,
            ...$this->quickViewRefreshPayload(),
        ];
    }

    /**
     * @return array{ok: bool}&array<string, mixed>
     */
    public function moveTodoStatus(int $todoId, string $status): array
    {
        $todo = Todo::query()->find($todoId);
        if ($todo === null) {
            return ['ok' => false, 'message' => 'Task not found.'];
        }

        if (! in_array($status, ['new', 'inprogress', 'confirm', 'returned', 'done'], true)) {
            return ['ok' => false, 'message' => 'Invalid status.'];
        }

        if (($todo->status?->value ?? 'new') === $status) {
            return [
                'ok' => true,
                ...$this->quickViewRefreshPayload(),
            ];
        }

        try {
            $todo->update(['status' => $status]);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not move task')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }

        return [
            'ok' => true,
            ...$this->quickViewRefreshPayload(),
        ];
    }

    /**
     * @return array{ok: bool}&array<string, mixed>
     */
    public function archiveTodo(int $todoId): array
    {
        $todo = Todo::query()->find($todoId);
        if ($todo === null) {
            Notification::make()
                ->title('Task not found')
                ->danger()
                ->send();

            return ['ok' => false];
        }

        if (! $todo->archived) {
            try {
                $todo->update(['archived' => true]);
            } catch (\Throwable $exception) {
                Notification::make()
                    ->title('Could not archive todo')
                    ->body($exception->getMessage())
                    ->danger()
                    ->send();

                return ['ok' => false];
            }

            Notification::make()
                ->title('Task archived')
                ->success()
                ->send();
        }

        return [
            'ok' => true,
            ...$this->quickViewRefreshPayload(),
        ];
    }

    /**
     * @return array{ok: bool}&array<string, mixed>
     */
    public function rescheduleTodo(int $todoId, ?string $start, ?string $end): array
    {
        $todo = Todo::query()->find($todoId);
        if ($todo === null) {
            Notification::make()
                ->title('Task not found')
                ->danger()
                ->send();

            return ['ok' => false];
        }

        if (! $this->canEditTodo($todo)) {
            Notification::make()
                ->title('Only task author can move this item')
                ->warning()
                ->send();

            return ['ok' => false];
        }

        if (blank($start)) {
            Notification::make()
                ->title('Invalid start date')
                ->warning()
                ->send();

            return ['ok' => false];
        }

        try {
            $startDate = \Illuminate\Support\Carbon::parse($start);
            $endDate = filled($end) ? \Illuminate\Support\Carbon::parse($end) : null;

            if ($endDate === null) {
                $existingStart = $todo->start_date instanceof \Illuminate\Support\Carbon
                    ? $todo->start_date
                    : null;
                $existingEnd = $todo->deadline instanceof \Illuminate\Support\Carbon
                    ? $todo->deadline
                    : null;
                $minutes = ($existingStart && $existingEnd && $existingEnd->gt($existingStart))
                    ? $existingStart->diffInMinutes($existingEnd)
                    : 60;
                $endDate = $startDate->copy()->addMinutes($minutes);
            }

            if ($endDate->lte($startDate)) {
                $endDate = $startDate->copy()->addHour();
            }

            $todo->update([
                'start_date' => $startDate,
                'deadline' => $endDate,
            ]);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not move task')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return ['ok' => false];
        }

        return [
            'ok' => true,
            ...$this->quickViewRefreshPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function todoToQuickViewPayload(Todo $todo): array
    {
        $hasFinances = (bool) $todo->has_finances || $this->hasFinanceValues($todo);

        return [
            'id' => $todo->getKey(),
            'title' => (string) $todo->title,
            'display_title' => $todo->displayTitle(),
            'project_id' => $todo->project_id ? (int) $todo->project_id : null,
            'status' => $todo->status?->value ?? 'new',
            'priority' => $todo->priority?->value ?? 'regular',
            'can_edit' => $this->canEditTodo($todo),
            'user_id' => $todo->user_id ? (int) $todo->user_id : null,
            'user_label' => (string) ($todo->user?->name ?? $todo->user?->email ?? ('User #'.$todo->user_id)),
            'edit_url' => route('filament.admin.resources.tasks.edit', ['record' => $todo->getKey()]),
            'start_date' => optional($todo->start_date)?->format('Y-m-d\TH:i'),
            'deadline' => optional($todo->deadline)?->format('Y-m-d\TH:i'),
            'description' => (string) ($todo->description ?? ''),
            'has_finances' => $hasFinances,
            'total_income' => $todo->total_income,
            'income_left' => $todo->income_left,
            'total_payment' => $todo->total_payment,
            'payment_left' => $todo->payment_left,
            'watcher_ids' => $todo->watchers->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'attachments' => $this->todoAttachmentPayload($todo),
            'archived' => (bool) $todo->archived,
        ];
    }

    /**
     * @return list<array{name: string, url: ?string, item_id: ?string, path: ?string}>
     */
    protected function todoAttachmentPayload(Todo $todo): array
    {
        $links = $todo->sharepointAttachmentLinks();
        if ($links !== []) {
            return $links;
        }

        $legacy = [];
        foreach (array_values(array_filter((array) ($todo->attachments ?? []))) as $path) {
            $path = (string) $path;
            $legacy[] = [
                'name' => basename(str_replace('\\', '/', $path)),
                'url' => str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                    ? $path
                    : asset('storage/'.ltrim($path, '/')),
                'item_id' => null,
                'path' => $path,
            ];
        }

        return $legacy;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function payloadToTodoAttributes(array $payload, int $userId, ?Todo $existing = null): array
    {
        $hasFinances = (bool) ($payload['has_finances'] ?? false);
        $newUploads = $this->storeQuickViewUploads();
        $keptSharepoint = $this->keptSharepointFilesFromPayload($payload['attachments'] ?? [], $existing);

        return [
            'user_id' => $userId,
            'project_id' => $this->resolveProjectId($payload['project_id'] ?? $existing?->project_id),
            'title' => trim((string) ($payload['title'] ?? '')),
            'status' => $payload['status'] ?? ($existing?->status?->value ?? 'new'),
            'priority' => $payload['priority'] ?? ($existing?->priority?->value ?? 'regular'),
            'start_date' => $this->parseQuickViewDate($payload['start_date'] ?? null) ?? $existing?->start_date,
            'deadline' => $this->parseQuickViewDate($payload['deadline'] ?? null) ?? $existing?->deadline,
            'description' => (string) ($payload['description'] ?? ''),
            'has_finances' => $hasFinances,
            'total_income' => $hasFinances ? $this->parseNullableDecimal($payload['total_income'] ?? null) : null,
            'income_left' => $hasFinances ? $this->parseNullableDecimal($payload['income_left'] ?? null) : null,
            'total_payment' => $hasFinances ? $this->parseNullableDecimal($payload['total_payment'] ?? null) : null,
            'payment_left' => $hasFinances ? $this->parseNullableDecimal($payload['payment_left'] ?? null) : null,
            'attachments' => null,
            'archived' => (bool) ($payload['archived'] ?? false),
            '_new_uploads' => $newUploads,
            '_kept_sharepoint_files' => $keptSharepoint,
        ];
    }

    /**
     * @param  mixed  $attachments
     * @return list<array{name: string, path: string, item_id: ?string, web_url: ?string}>|null
     */
    protected function keptSharepointFilesFromPayload(mixed $attachments, ?Todo $existing): ?array
    {
        if ($existing === null) {
            return null;
        }

        $rows = is_array($attachments) ? $attachments : [];
        $hasObjectRows = false;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $hasObjectRows = true;
                break;
            }
        }

        // Legacy string paths (or empty non-object payload): do not rewrite SharePoint metadata.
        if (! $hasObjectRows) {
            return null;
        }

        $existingByItem = [];
        foreach ($existing->sharepointAttachmentLinks() as $file) {
            if (filled($file['item_id'] ?? null)) {
                $existingByItem[(string) $file['item_id']] = [
                    'name' => $file['name'],
                    'path' => $file['path'] ?? '',
                    'item_id' => $file['item_id'],
                    'web_url' => $file['url'],
                ];
            }
        }

        $kept = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $itemId = filled($row['item_id'] ?? null) ? (string) $row['item_id'] : null;
            if ($itemId !== null && isset($existingByItem[$itemId])) {
                $kept[] = $existingByItem[$itemId];
            }
        }

        return $kept;
    }

    /**
     * @param  list<string>  $localPaths
     */
    protected function ingestQuickViewUploads(Todo $todo, array $localPaths): void
    {
        $localPaths = array_values(array_filter($localPaths, fn ($path): bool => filled($path)));
        if ($localPaths === []) {
            return;
        }

        if (! SharepointSetting::instance()->isReady()) {
            DocumentBinaryStore::deleteLocalPaths($localPaths);
            Notification::make()
                ->title('SharePoint required')
                ->body('Task files are stored only on SharePoint. Enable and configure System → SharePoint, then upload again.')
                ->danger()
                ->send();

            return;
        }

        try {
            SharepointTaskUploader::ingestLocalUploads($todo, $localPaths);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('SharePoint upload failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return list<string>
     */
    protected function storeQuickViewUploads(): array
    {
        $paths = [];

        foreach ($this->quickViewUploads as $file) {
            if ($file instanceof TemporaryUploadedFile) {
                $paths[] = $file->store('todos/tmp', 'public');
            }
        }

        return $paths;
    }

    /**
     * @param  mixed  $watcherIds
     * @return list<int>
     */
    protected function resolveWatcherIds(mixed $watcherIds): array
    {
        return collect(is_array($watcherIds) ? $watcherIds : [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    protected function resolveUserId(mixed $userId): ?int
    {
        $resolved = (int) ($userId ?: (auth()->id() ?? 0));

        return $resolved > 0 ? $resolved : null;
    }

    protected function resolveProjectId(mixed $projectId): ?int
    {
        $resolved = (int) $projectId;

        if ($resolved <= 0) {
            return null;
        }

        return Project::query()->whereKey($resolved)->exists() ? $resolved : null;
    }

    protected function canEditTodo(Todo $todo): bool
    {
        $isAdmin = auth()->user()?->hasRole('admin') ?? false;
        $isAuthor = (int) ($todo->user_id ?? 0) === (int) (auth()->id() ?? 0);

        return $isAdmin || $isAuthor;
    }

    protected function parseNullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }

    protected function parseQuickViewDate(mixed $value): ?\Illuminate\Support\Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim(str_replace('T', ' ', (string) $value));

        try {
            return \Illuminate\Support\Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function hasFinanceValues(Todo $todo): bool
    {
        foreach ([
            $todo->total_income,
            $todo->income_left,
            $todo->total_payment,
            $todo->payment_left,
        ] as $value) {
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    protected function hasIncomeValues(Todo $todo): bool
    {
        return ($todo->total_income !== null && $todo->total_income !== '')
            || ($todo->income_left !== null && $todo->income_left !== '');
    }

    protected function hasPaymentValues(Todo $todo): bool
    {
        return ($todo->total_payment !== null && $todo->total_payment !== '')
            || ($todo->payment_left !== null && $todo->payment_left !== '');
    }

    protected function statusColor(?string $status): string
    {
        return match ($status) {
            'done' => '#16a34a',
            'inprogress' => '#f59e0b',
            'confirm' => '#2563eb',
            'returned' => '#9333ea',
            default => '#6b7280',
        };
    }
}
