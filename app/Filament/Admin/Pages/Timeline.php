<?php

namespace App\Filament\Admin\Pages;

use App\Models\Project;
use App\Models\Todo;
use App\Models\TodoComment;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use UnitEnum;

class Timeline extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Timeline';

    protected static string|UnitEnum|null $navigationGroup = 'Tasks';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.timeline';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTimelineItems(): array
    {
        return Todo::query()
            ->notArchived()
            ->with(['user', 'project', 'comments.user'])
            ->orderBy('deadline')
            ->get()
            ->map(function (Todo $todo): array {
                $start = $todo->start_date instanceof Carbon
                    ? $todo->start_date
                    : ($todo->deadline instanceof Carbon ? $todo->deadline->copy()->subHour() : null);

                $end = $todo->deadline instanceof Carbon ? $todo->deadline : null;

                return [
                    'id' => $todo->getKey(),
                    'title' => $todo->title,
                    'display_title' => $todo->displayTitle(),
                    'project_id' => $todo->project_id ? (int) $todo->project_id : null,
                    'user_id' => $todo->user_id,
                    'user_label' => (string) ($todo->user?->name ?? $todo->user?->email ?? ('User #'.$todo->user_id)),
                    'start' => $start?->toIso8601String(),
                    'end' => $end?->toIso8601String(),
                    'start_label' => $start?->format('Y-m-d H:i:s') ?? '—',
                    'end_label' => $end?->format('Y-m-d H:i:s') ?? '—',
                    'duration_label' => $this->durationLabel($start, $end),
                    'color' => $this->eventColor($todo),
                    'status' => $todo->status?->value ?? null,
                    'priority' => $todo->priority?->value ?? 'regular',
                    'archived' => (bool) $todo->archived,
                    'can_edit' => (int) ($todo->user_id ?? 0) === (int) (auth()->id() ?? 0),
                    'description' => Str::limit((string) ($todo->description ?? ''), 300, '...'),
                    'full_description' => (string) ($todo->description ?? ''),
                    'total_income' => $todo->total_income,
                    'income_left' => $todo->income_left,
                    'total_payment' => $todo->total_payment,
                    'payment_left' => $todo->payment_left,
                    'has_financials' => $this->hasFinanceValues($todo),
                    'has_income' => $this->hasIncomeValues($todo),
                    'has_payments' => $this->hasPaymentValues($todo),
                    'comments_count' => $todo->comments->count(),
                    'comments' => $todo->comments
                        ->sortByDesc('created_at')
                        ->values()
                        ->map(function (TodoComment $comment): array {
                            return [
                                'id' => $comment->getKey(),
                                'user_id' => $comment->user_id,
                                'is_owner' => (int) ($comment->user_id ?? 0) === (int) (auth()->id() ?? 0),
                                'delete' => false,
                                'user' => (string) ($comment->user?->name ?? $comment->user?->email ?? 'Unknown user'),
                                'date' => (string) ($comment->created_at?->format('Y-m-d H:i:s') ?? ''),
                                'content' => (string) ($comment->content ?? ''),
                            ];
                        })
                        ->all(),
                    'edit_url' => route('filament.admin.resources.tasks.edit', ['record' => $todo->getKey()]),
                ];
            })
            ->values()
            ->all();
    }

    public function addComment(int $todoId, string $content): bool
    {
        $text = trim($content);
        if ($text === '') {
            Notification::make()
                ->title('Comment text is required')
                ->warning()
                ->send();

            return false;
        }

        $todo = Todo::query()->find($todoId);
        if ($todo === null) {
            Notification::make()
                ->title('Task not found')
                ->danger()
                ->send();

            return false;
        }

        TodoComment::query()->create([
            'todo_id' => $todo->getKey(),
            'user_id' => auth()->id(),
            'content' => $text,
            'attachments' => [],
        ]);

        Notification::make()
            ->title('Comment added')
            ->success()
            ->send();

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $comments
     */
    public function saveComments(int $todoId, array $comments, string $newContent = ''): bool
    {
        $todo = Todo::query()->find($todoId);
        if ($todo === null) {
            Notification::make()
                ->title('Task not found')
                ->danger()
                ->send();

            return false;
        }

        $rows = collect($comments)->keyBy(fn (array $row): string => (string) ($row['id'] ?? ''));
        $todo->comments()->get()->each(function (TodoComment $comment) use ($rows): void {
            if ((int) $comment->user_id !== (int) auth()->id()) {
                return;
            }

            $row = $rows->get((string) $comment->getKey());
            if (! is_array($row)) {
                return;
            }

            if ((bool) ($row['delete'] ?? false)) {
                $comment->delete();

                return;
            }

            $comment->update([
                'content' => trim((string) ($row['content'] ?? '')),
            ]);
        });

        $newText = trim($newContent);
        if ($newText !== '') {
            TodoComment::query()->create([
                'todo_id' => $todo->getKey(),
                'user_id' => auth()->id(),
                'content' => $newText,
                'attachments' => [],
            ]);
        }

        Notification::make()
            ->title('Comments updated')
            ->success()
            ->send();

        return true;
    }

    /**
     * @return array{projects: array<int, array{id: int, label: string, archived: bool}>}
     */
    public function getTimelineMeta(): array
    {
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
            'projects' => $projects,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateTodoQuickView(int $todoId, array $payload): bool
    {
        $todo = Todo::query()->find($todoId);
        if ($todo === null) {
            Notification::make()
                ->title('Task not found')
                ->danger()
                ->send();

            return false;
        }

        if ((int) ($todo->user_id ?? 0) !== (int) (auth()->id() ?? 0)) {
            Notification::make()
                ->title('Only task author can update this item')
                ->warning()
                ->send();

            return false;
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            Notification::make()
                ->title('Title is required')
                ->warning()
                ->send();

            return false;
        }

        $projectId = (int) ($payload['project_id'] ?? 0);
        if ($projectId <= 0 || ! Project::query()->whereKey($projectId)->exists()) {
            Notification::make()
                ->title('Project is required')
                ->warning()
                ->send();

            return false;
        }

        try {
            $todo->update([
                'title' => $title,
                'project_id' => $projectId,
                'status' => $payload['status'] ?? ($todo->status?->value ?? null),
                'priority' => $payload['priority'] ?? ($todo->priority?->value ?? 'regular'),
                'start_date' => $payload['start_date'] ?? $todo->start_date,
                'deadline' => $payload['deadline'] ?? $todo->deadline,
                'description' => (string) ($payload['description'] ?? ''),
                'archived' => (bool) ($payload['archived'] ?? false),
            ]);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not update task')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title('Task updated')
            ->success()
            ->send();

        return true;
    }

    /**
     * @return array{ok: bool, items?: array<int, array<string, mixed>>}
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
            $todo->update(['archived' => true]);

            Notification::make()
                ->title('Task archived')
                ->success()
                ->send();
        }

        return [
            'ok' => true,
            'items' => $this->getTimelineItems(),
        ];
    }

    private function durationLabel(?Carbon $start, ?Carbon $end): string
    {
        if ($start === null || $end === null || $end->lt($start)) {
            return '—';
        }

        $minutes = $start->diffInMinutes($end);
        $days = intdiv($minutes, 60 * 24);
        $hours = intdiv($minutes % (60 * 24), 60);
        $mins = $minutes % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days.'d';
        }
        if ($hours > 0) {
            $parts[] = $hours.'h';
        }
        if ($mins > 0 || $parts === []) {
            $parts[] = $mins.'m';
        }

        return implode(' ', $parts);
    }

    private function eventColor(Todo $todo): string
    {
        return match ($todo->status?->value) {
            'done' => '#16a34a',
            'inprogress' => '#f59e0b',
            'confirm' => '#2563eb',
            'returned' => '#9333ea',
            default => '#6b7280',
        };
    }

    private function hasFinanceValues(Todo $todo): bool
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

    private function hasIncomeValues(Todo $todo): bool
    {
        return ($todo->total_income !== null && $todo->total_income !== '')
            || ($todo->income_left !== null && $todo->income_left !== '');
    }

    private function hasPaymentValues(Todo $todo): bool
    {
        return ($todo->total_payment !== null && $todo->total_payment !== '')
            || ($todo->payment_left !== null && $todo->payment_left !== '');
    }
}
