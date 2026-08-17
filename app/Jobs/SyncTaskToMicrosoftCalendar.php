<?php

namespace App\Jobs;

use App\Models\Todo;
use App\Services\Calendar\CalendarSyncContext;
use App\Services\Calendar\CalendarSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncTaskToMicrosoftCalendar implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public int $todoId,
    ) {
        $this->onQueue('calendar');
    }

    public function uniqueId(): string
    {
        return 'sync-todo-calendar-'.$this->todoId;
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(CalendarSyncService $sync): void
    {
        if (CalendarSyncContext::isFromExternal()) {
            return;
        }

        $todo = Todo::query()->find($this->todoId);
        if ($todo === null) {
            return;
        }

        $sync->pushTodoToOutlook($todo);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SyncTaskToMicrosoftCalendar failed', [
            'todo_id' => $this->todoId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
