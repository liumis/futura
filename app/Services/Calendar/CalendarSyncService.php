<?php

namespace App\Services\Calendar;

use App\Enums\ActivityLogEvent;
use App\Enums\TaskCalendarExternalStatus;
use App\Models\ActivityLog;
use App\Models\CalendarConnection;
use App\Models\TaskCalendarEvent;
use App\Models\Todo;
use App\Support\Calendar\ExternalCalendarEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalendarSyncService
{
    public function __construct(
        protected MicrosoftCalendarProvider $provider,
    ) {}

    public function syncHashForTodo(Todo $todo): string
    {
        $start = optional($todo->start_date)?->utc()->format('Y-m-d\TH:i:s');
        $end = optional($todo->deadline)?->utc()->format('Y-m-d\TH:i:s');

        return hash('sha256', implode('|', [
            (string) $todo->title,
            $start ?? '',
            $end ?? '',
            $todo->all_day ? '1' : '0',
        ]));
    }

    public function pushTodoToOutlook(Todo $todo, ?CalendarConnection $connection = null): ?TaskCalendarEvent
    {
        if (! $todo->calendar_sync_enabled || $todo->archived) {
            return null;
        }

        $connection ??= CalendarConnection::query()
            ->where('user_id', $todo->user_id)
            ->where('status', 'active')
            ->first();

        if ($connection === null || ! $connection->isActive()) {
            return null;
        }

        $lock = Cache::lock('calendar-sync-todo-'.$todo->getKey(), 30);
        if (! $lock->get()) {
            return TaskCalendarEvent::query()
                ->where('todo_id', $todo->getKey())
                ->where('calendar_connection_id', $connection->getKey())
                ->first();
        }

        try {
            $mapping = TaskCalendarEvent::query()->firstOrNew([
                'todo_id' => $todo->getKey(),
                'calendar_connection_id' => $connection->getKey(),
            ]);

            $hash = $this->syncHashForTodo($todo);
            if (
                $mapping->exists
                && $mapping->sync_hash === $hash
                && $mapping->external_status === TaskCalendarExternalStatus::Synced
                && filled($mapping->external_event_id)
            ) {
                return $mapping;
            }

            if ($todo->start_date === null || $todo->deadline === null) {
                $mapping->forceFill([
                    'external_status' => TaskCalendarExternalStatus::Error,
                    'last_error' => 'Task is missing start_date or deadline.',
                ])->save();

                return $mapping;
            }

            $payload = $this->provider->buildEventPayload(
                (string) $todo->title,
                $todo->start_date,
                $todo->deadline,
                (bool) $todo->all_day,
            );

            if ($mapping->isDeletedExternally() || blank($mapping->external_event_id)) {
                $event = $this->provider->createEvent($connection, $payload);
            } else {
                try {
                    $event = $this->provider->updateEvent($connection, (string) $mapping->external_event_id, $payload);
                } catch (Throwable $exception) {
                    // Event missing remotely — recreate.
                    Log::warning('Outlook event update failed; recreating', [
                        'todo_id' => $todo->getKey(),
                        'message' => $exception->getMessage(),
                    ]);
                    $event = $this->provider->createEvent($connection, $payload);
                }
            }

            $mapping->forceFill([
                'external_event_id' => $event->id,
                'last_external_event_id' => $event->id,
                'external_change_key' => $event->changeKey,
                'external_status' => TaskCalendarExternalStatus::Synced,
                'last_external_modified_at' => $event->lastModified,
                'last_synced_at' => now(),
                'deleted_externally_at' => null,
                'sync_hash' => $hash,
                'last_sync_origin' => 'local',
                'last_error' => null,
            ])->save();

            return $mapping->fresh() ?? $mapping;
        } catch (Throwable $exception) {
            Log::error('pushTodoToOutlook failed', [
                'todo_id' => $todo->getKey(),
                'message' => $exception->getMessage(),
            ]);

            TaskCalendarEvent::query()->updateOrCreate(
                [
                    'todo_id' => $todo->getKey(),
                    'calendar_connection_id' => $connection->getKey(),
                ],
                [
                    'external_status' => TaskCalendarExternalStatus::Error,
                    'last_error' => $exception->getMessage(),
                ],
            );

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function applyExternalEventToTodo(
        CalendarConnection $connection,
        ExternalCalendarEvent $event,
    ): ?Todo {
        $mapping = TaskCalendarEvent::query()
            ->where('calendar_connection_id', $connection->getKey())
            ->where(function ($q) use ($event): void {
                $q->where('external_event_id', $event->id)
                    ->orWhere('last_external_event_id', $event->id);
            })
            ->first();

        if ($mapping === null) {
            return null;
        }

        $todo = $mapping->todo;
        if ($todo === null) {
            return null;
        }

        // Loop prevention: same change key / hash already applied.
        if (
            filled($event->changeKey)
            && $mapping->external_change_key === $event->changeKey
            && $mapping->last_sync_origin === 'local'
        ) {
            return $todo;
        }

        if (
            $event->lastModified !== null
            && $mapping->last_synced_at !== null
            && $event->lastModified->lt($mapping->last_synced_at)
            && $mapping->last_sync_origin === 'local'
        ) {
            Log::info('Skipping older Outlook event vs newer local sync', [
                'todo_id' => $todo->getKey(),
                'event_id' => $event->id,
            ]);

            return $todo;
        }

        $start = $event->start;
        $end = $event->end;
        if ($event->allDay && $end !== null) {
            // Graph all-day end is exclusive next day.
            $end = $end->copy()->subDay()->endOfDay();
        }

        if ($start === null || $end === null) {
            return $todo;
        }

        $incomingHash = hash('sha256', implode('|', [
            (string) ($event->subject ?? $todo->title),
            $start->utc()->format('Y-m-d\TH:i:s'),
            $end->utc()->format('Y-m-d\TH:i:s'),
            $event->allDay ? '1' : '0',
        ]));

        if ($mapping->sync_hash === $incomingHash) {
            $mapping->forceFill([
                'external_change_key' => $event->changeKey,
                'last_external_modified_at' => $event->lastModified,
                'last_synced_at' => now(),
                'external_status' => TaskCalendarExternalStatus::Synced,
                'deleted_externally_at' => null,
                'external_event_id' => $event->id,
            ])->save();

            return $todo;
        }

        // Avoid observer → push loop: temporarily disable calendar push via flag.
        CalendarSyncContext::runningExternal(function () use ($todo, $event, $start, $end, $mapping, $incomingHash, $connection): void {
            $todo->forceFill([
                'start_date' => $start,
                'deadline' => $end,
                'all_day' => $event->allDay,
                // Title stays app-owned; Outlook subject changes do not rename the Task.
            ])->save();

            $mapping->forceFill([
                'external_event_id' => $event->id,
                'last_external_event_id' => $event->id,
                'external_change_key' => $event->changeKey,
                'external_status' => TaskCalendarExternalStatus::Synced,
                'last_external_modified_at' => $event->lastModified,
                'last_synced_at' => now(),
                'deleted_externally_at' => null,
                'sync_hash' => $this->syncHashForTodo($todo->fresh() ?? $todo),
                'last_sync_origin' => 'microsoft',
                'last_error' => null,
            ])->save();

            $this->audit(
                ActivityLogEvent::TodoCalendarSchedulingUpdated,
                'Todo #'.$todo->getKey().' scheduling updated from Microsoft Outlook',
                $todo,
                [
                    'source' => 'microsoft_calendar',
                    'event' => 'scheduling_updated',
                    'external_event_id' => $event->id,
                    'sync_hash' => $incomingHash,
                ],
                $connection->user_id,
            );
        });

        return $todo->fresh() ?? $todo;
    }

    public function markExternallyDeleted(CalendarConnection $connection, string $externalEventId): ?TaskCalendarEvent
    {
        $mapping = TaskCalendarEvent::query()
            ->where('calendar_connection_id', $connection->getKey())
            ->where('external_event_id', $externalEventId)
            ->first();

        if ($mapping === null) {
            return null;
        }

        if ($mapping->isDeletedExternally()) {
            return $mapping;
        }

        $mapping->forceFill([
            'last_external_event_id' => $mapping->external_event_id ?? $externalEventId,
            'external_event_id' => null,
            'external_status' => TaskCalendarExternalStatus::DeletedExternally,
            'deleted_externally_at' => now(),
            'last_synced_at' => now(),
            'last_sync_origin' => 'microsoft',
        ])->save();

        $todo = $mapping->todo;
        if ($todo !== null) {
            $this->audit(
                ActivityLogEvent::TodoCalendarEventDeletedExternally,
                'Outlook calendar event deleted for Todo #'.$todo->getKey().' (task kept)',
                $todo,
                [
                    'source' => 'microsoft_calendar',
                    'event' => 'external_calendar_event_deleted',
                    'last_external_event_id' => $mapping->last_external_event_id,
                    'deleted_externally_at' => optional($mapping->deleted_externally_at)?->toIso8601String(),
                ],
                $connection->user_id,
            );
        }

        return $mapping;
    }

    /**
     * Recreate Outlook event for a mapping marked deleted_externally (or missing event).
     */
    public function restoreTodoToOutlook(Todo $todo): ?TaskCalendarEvent
    {
        $todo->forceFill(['calendar_sync_enabled' => true])->save();

        $mapping = TaskCalendarEvent::query()->where('todo_id', $todo->getKey())->first();
        if ($mapping !== null) {
            $mapping->forceFill([
                'external_event_id' => null,
                'external_status' => TaskCalendarExternalStatus::Pending,
                'deleted_externally_at' => null,
                'sync_hash' => null,
            ])->save();
        }

        return $this->pushTodoToOutlook($todo->fresh() ?? $todo);
    }

    public function syncConnectionDelta(CalendarConnection $connection): void
    {
        $lock = Cache::lock('calendar-delta-'.$connection->getKey(), 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $result = $this->provider->deltaSync($connection, $connection->delta_link);

            foreach ($result['deleted_ids'] as $deletedId) {
                $this->markExternallyDeleted($connection, $deletedId);
            }

            foreach ($result['events'] as $event) {
                $this->applyExternalEventToTodo($connection, $event);
            }

            $connection->forceFill([
                'delta_link' => $result['delta_link'] ?? $connection->delta_link,
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $connection->forceFill([
                'last_error' => $exception->getMessage(),
            ])->save();

            Log::error('Calendar delta sync failed', [
                'connection_id' => $connection->getKey(),
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function deleteOutlookEventForTodo(Todo $todo): void
    {
        $mappings = TaskCalendarEvent::query()
            ->where('todo_id', $todo->getKey())
            ->with('calendarConnection')
            ->get();

        foreach ($mappings as $mapping) {
            $connection = $mapping->calendarConnection;
            if ($connection === null || ! filled($mapping->external_event_id)) {
                $mapping->delete();
                continue;
            }

            try {
                $this->provider->deleteEvent($connection, (string) $mapping->external_event_id);
            } catch (Throwable $exception) {
                Log::warning('Failed deleting Outlook event for deleted todo', [
                    'todo_id' => $todo->getKey(),
                    'message' => $exception->getMessage(),
                ]);
            }

            $mapping->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function audit(
        ActivityLogEvent $event,
        string $description,
        Todo $todo,
        array $properties,
        ?int $userId,
    ): void {
        $request = request();

        ActivityLog::query()->create([
            'user_id' => $userId,
            'event_key' => $event->value,
            'subject_type' => $todo->getMorphClass(),
            'subject_id' => $todo->getKey(),
            'description' => $description,
            'properties' => $properties,
            'ip_address' => $request?->ip(),
            'user_agent' => filled($request?->userAgent())
                ? substr((string) $request->userAgent(), 0, 1000)
                : null,
        ]);
    }
}
