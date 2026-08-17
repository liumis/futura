<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:notify-low-stock')->dailyAt('07:00');
Schedule::command('app:sync-dokobit-signings')->everyFiveMinutes();
Schedule::command('app:prune-activity-logs')->dailyAt('02:30');

// Outlook calendar: renew Graph subscriptions before expiry; safety delta every 15 minutes.
Schedule::job(new \App\Jobs\RenewMicrosoftCalendarSubscriptions)->hourly();
Schedule::call(function (): void {
    \App\Models\CalendarConnection::query()
        ->where('status', \App\Enums\CalendarConnectionStatus::Active)
        ->whereNotNull('calendar_id')
        ->pluck('id')
        ->each(fn ($id) => \App\Jobs\SyncMicrosoftCalendarChanges::dispatch((int) $id));
})->everyFifteenMinutes()->name('microsoft-calendar-delta-safety')->withoutOverlapping();
