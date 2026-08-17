<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\Todo;
use App\Services\ActivityLogger;
use App\Services\TodoNotificationMailer;

class TodoObserver
{
    public function updated(Todo $todo): void
    {
        ActivityLogger::log(
            ActivityLogEvent::TodoUpdated,
            'Todo #'.$todo->id.' updated: '.$todo->title,
            $todo,
            ['changes' => $todo->getChanges()],
        );

        if ($todo->wasChanged('status')) {
            TodoNotificationMailer::notifyStatusChanged(
                $todo,
                $todo->getOriginal('status'),
                $todo->status,
            );
        }

        if (\App\Services\Calendar\CalendarSyncContext::isFromExternal()) {
            return;
        }

        $scheduleChanged = $todo->wasChanged([
            'title',
            'start_date',
            'deadline',
            'all_day',
            'calendar_sync_enabled',
            'archived',
        ]);

        if ($scheduleChanged && ($todo->calendar_sync_enabled || $todo->wasChanged('calendar_sync_enabled'))) {
            \App\Jobs\SyncTaskToMicrosoftCalendar::dispatch($todo->getKey());
        }
    }

    public function created(Todo $todo): void
    {
        ActivityLogger::log(
            ActivityLogEvent::TodoCreated,
            'Todo #'.$todo->id.' created: '.$todo->title,
            $todo,
        );

        if (
            ! \App\Services\Calendar\CalendarSyncContext::isFromExternal()
            && $todo->calendar_sync_enabled
        ) {
            \App\Jobs\SyncTaskToMicrosoftCalendar::dispatch($todo->getKey());
        }
    }

    public function deleted(Todo $todo): void
    {
        ActivityLogger::log(
            ActivityLogEvent::TodoDeleted,
            'Todo #'.$todo->id.' deleted: '.$todo->title,
            null,
            ['deleted_todo_id' => $todo->getKey()],
        );

        // Application-initiated Task deletion also removes the Outlook representation.
        // Outlook-initiated event deletion never deletes the Task.
        try {
            app(\App\Services\Calendar\CalendarSyncService::class)->deleteOutlookEventForTodo($todo);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Could not delete Outlook event for deleted todo', [
                'todo_id' => $todo->getKey(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
