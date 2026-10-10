<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PruneActivityLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_logs_older_than_three_years_and_keeps_newer(): void
    {
        Carbon::setTestNow('2026-08-23 12:00:00');

        $old = now()->subYears(3)->subDay();
        $kept = now()->subYears(3)->addDay();

        ActivityLog::query()->insert([
            ['event_key' => 'old.activity', 'description' => 'old', 'created_at' => $old],
            ['event_key' => 'new.activity', 'description' => 'new', 'created_at' => $kept],
        ]);

        NotificationLog::query()->insert([
            [
                'channel' => 'database',
                'notification_type' => 'document_expiration',
                'title' => 'old',
                'body' => 'old body',
                'created_at' => $old,
            ],
            [
                'channel' => 'database',
                'notification_type' => 'document_expiration',
                'title' => 'new',
                'body' => 'new body',
                'created_at' => $kept,
            ],
        ]);

        EmailLog::query()->insert([
            ['subject' => 'old', 'to' => json_encode(['old@example.com']), 'created_at' => $old],
            ['subject' => 'new', 'to' => json_encode(['new@example.com']), 'created_at' => $kept],
        ]);

        $this->artisan('app:prune-activity-logs')
            ->assertSuccessful();

        $this->assertSame(1, ActivityLog::query()->where('event_key', 'new.activity')->count());
        $this->assertSame(0, ActivityLog::query()->where('event_key', 'old.activity')->count());
        $this->assertSame(1, NotificationLog::query()->where('title', 'new')->count());
        $this->assertSame(0, NotificationLog::query()->where('title', 'old')->count());
        $this->assertSame(1, EmailLog::query()->where('subject', 'new')->count());
        $this->assertSame(0, EmailLog::query()->where('subject', 'old')->count());

        Carbon::setTestNow();
    }
}
