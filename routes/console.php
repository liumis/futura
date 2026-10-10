<?php

use App\Enums\CalendarConnectionStatus;
use App\Jobs\RenewMicrosoftCalendarSubscriptions;
use App\Jobs\SyncMicrosoftCalendarChanges;
use App\Models\CalendarConnection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:update-currencies')->dailyAt('07:05')->withoutOverlapping();
Schedule::command('app:notify-low-stock')->dailyAt('07:00');
Schedule::command('app:notify-draft-orders')->weekdays()->dailyAt('08:00');
Schedule::command('app:notify-document-expirations')->dailyAt('07:15');
Schedule::command('app:notify-document-confirmations')->dailyAt('07:20');
Schedule::command('app:sync-dokobit-signings')->everyFiveMinutes();
Schedule::command('app:prune-activity-logs')->dailyAt('02:30')->withoutOverlapping();

// Outlook calendar: renew Graph subscriptions before expiry; safety delta every 15 minutes.
Schedule::job(new RenewMicrosoftCalendarSubscriptions)->hourly();
Schedule::call(function (): void {
    CalendarConnection::query()
        ->where('status', CalendarConnectionStatus::Active)
        ->whereNotNull('calendar_id')
        ->pluck('id')
        ->each(fn ($id) => SyncMicrosoftCalendarChanges::dispatch((int) $id));
})->everyFifteenMinutes()->name('microsoft-calendar-delta-safety')->withoutOverlapping();
