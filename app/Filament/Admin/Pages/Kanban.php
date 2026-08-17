<?php

namespace App\Filament\Admin\Pages;

use App\Enums\TodoStatus;
use App\Filament\Admin\Concerns\ManagesTodoQuickView;
use App\Models\Todo;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;

class Kanban extends Page
{
    use ManagesTodoQuickView;
    use WithFileUploads;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Kanban';

    protected static string|\UnitEnum|null $navigationGroup = 'Tasks';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.admin.pages.kanban';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    public function getKanbanColumns(): array
    {
        return collect(TodoStatus::cases())
            ->map(fn (TodoStatus $status): array => [
                'value' => $status->value,
                'label' => $status->getLabel() ?? $status->value,
                'color' => $this->statusColor($status->value),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getKanbanItems(): array
    {
        return Todo::query()
            ->notArchived()
            ->with(['user', 'project'])
            ->withCount('comments')
            ->orderBy('deadline')
            ->get()
            ->map(fn (Todo $todo): array => $this->mapKanbanItem($todo))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapKanbanItem(Todo $todo): array
    {
        return [
            'id' => $todo->getKey(),
            'title' => $todo->displayTitle(),
            'status' => $todo->status?->value ?? 'new',
            'priority' => $todo->priority?->value ?? 'regular',
            'color' => $this->statusColor($todo->status?->value),
            'can_edit' => $this->canEditTodo($todo),
            'user_label' => (string) ($todo->user?->name ?? $todo->user?->email ?? ('User #'.$todo->user_id)),
            'deadline_label' => optional($todo->deadline)?->format('Y-m-d H:i:s') ?? '—',
            'description' => Str::limit((string) ($todo->description ?? ''), 300, '...'),
            'full_description' => (string) ($todo->description ?? ''),
            'total_income' => $todo->total_income,
            'income_left' => $todo->income_left,
            'total_payment' => $todo->total_payment,
            'payment_left' => $todo->payment_left,
            'has_financials' => $this->hasFinanceValues($todo),
            'comments_count' => (int) ($todo->comments_count ?? 0),
            'edit_url' => route('filament.admin.resources.tasks.edit', ['record' => $todo->getKey()]),
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>}
     */
    protected function quickViewRefreshPayload(): array
    {
        return [
            'items' => $this->getKanbanItems(),
        ];
    }
}
