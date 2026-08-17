<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Services\Calendar\CalendarSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMicrosoftCalendarChanges implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public int $calendarConnectionId,
    ) {
        $this->onQueue('calendar');
    }

    public function uniqueId(): string
    {
        return 'sync-calendar-delta-'.$this->calendarConnectionId;
    }

    public function uniqueFor(): int
    {
        return 120;
    }

    public function backoff(): array
    {
        return [15, 45, 90];
    }

    public function handle(CalendarSyncService $sync): void
    {
        $connection = CalendarConnection::query()->find($this->calendarConnectionId);
        if ($connection === null || ! $connection->isActive()) {
            return;
        }

        $sync->syncConnectionDelta($connection);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SyncMicrosoftCalendarChanges failed', [
            'connection_id' => $this->calendarConnectionId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
