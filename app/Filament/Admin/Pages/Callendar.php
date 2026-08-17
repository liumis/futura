<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\ManagesTodoQuickView;
use App\Models\Todo;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;

class Callendar extends Page
{
    use ManagesTodoQuickView;
    use WithFileUploads;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Callendar';

    protected static string|\UnitEnum|null $navigationGroup = 'Tasks';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.callendar';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCalendarEvents(): array
    {
        return Todo::query()
            ->notArchived()
            ->with(['user', 'project'])
            ->withCount('comments')
            ->orderBy('deadline')
            ->get()
            ->map(fn (Todo $todo): array => [
                'id' => $todo->getKey(),
                'title' => $todo->displayTitle(),
                'start' => optional($todo->start_date)->toIso8601String() ?? optional($todo->deadline)->copy()->subHour()->toIso8601String(),
                'end' => optional($todo->deadline)->toIso8601String(),
                'color' => $this->statusColor($todo->status?->value),
                'extendedProps' => [
                    'todo_id' => $todo->getKey(),
                    'status' => $todo->status?->value ?? null,
                    'can_edit' => $this->canEditTodo($todo),
                    'user' => $todo->user?->name ?? ('User #'.$todo->user_id),
                    'user_label' => (string) ($todo->user?->name ?? $todo->user?->email ?? ('User #'.$todo->user_id)),
                    'description' => Str::limit((string) ($todo->description ?? ''), 300, '...'),
                    'full_description' => (string) ($todo->description ?? ''),
                    'start_date' => optional($todo->start_date)?->format('Y-m-d\TH:i'),
                    'deadline' => optional($todo->deadline)?->format('Y-m-d\TH:i'),
                    'total_income' => $todo->total_income,
                    'income_left' => $todo->income_left,
                    'total_payment' => $todo->total_payment,
                    'payment_left' => $todo->payment_left,
                    'has_financials' => $this->hasFinanceValues($todo),
                    'has_income' => $this->hasIncomeValues($todo),
                    'has_payments' => $this->hasPaymentValues($todo),
                    'comments_count' => (int) ($todo->comments_count ?? 0),
                    'edit_url' => route('filament.admin.resources.tasks.edit', ['record' => $todo->getKey()]),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{events: array<int, array<string, mixed>>}
     */
    protected function quickViewRefreshPayload(): array
    {
        return [
            'events' => $this->getCalendarEvents(),
        ];
    }
}
