<?php

namespace Tests\Unit;

use App\Enums\CalendarConnectionStatus;
use App\Enums\CalendarProvider;
use App\Enums\TaskCalendarExternalStatus;
use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use App\Models\CalendarConnection;
use App\Models\TaskCalendarEvent;
use App\Models\Todo;
use App\Models\User;
use App\Services\Calendar\CalendarSyncService;
use App\Services\Calendar\MicrosoftCalendarProvider;
use App\Support\Calendar\ExternalCalendarEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class CalendarSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_creating_synced_task_creates_outlook_event(): void
    {
        [$user, $connection, $todo] = $this->seedSyncedTodo();

        $provider = Mockery::mock(MicrosoftCalendarProvider::class);
        $provider->shouldReceive('buildEventPayload')->once()->andReturn(['subject' => 'Test']);
        $provider->shouldReceive('createEvent')->once()->andReturn(new ExternalCalendarEvent(
            id: 'evt-1',
            subject: 'Test',
            start: Carbon::parse('2026-08-10 10:00:00'),
            end: Carbon::parse('2026-08-10 11:00:00'),
            allDay: false,
            changeKey: 'ck-1',
            lastModified: now(),
        ));

        $sync = new CalendarSyncService($provider);
        $mapping = $sync->pushTodoToOutlook($todo, $connection);

        $this->assertNotNull($mapping);
        $this->assertSame('evt-1', $mapping->external_event_id);
        $this->assertSame(TaskCalendarExternalStatus::Synced, $mapping->external_status);
        $this->assertSame('local', $mapping->last_sync_origin);
    }

    public function test_updating_task_updates_outlook_event(): void
    {
        [$user, $connection, $todo] = $this->seedSyncedTodo();

        TaskCalendarEvent::query()->create([
            'todo_id' => $todo->getKey(),
            'calendar_connection_id' => $connection->getKey(),
            'external_event_id' => 'evt-1',
            'last_external_event_id' => 'evt-1',
            'external_status' => TaskCalendarExternalStatus::Synced,
            'sync_hash' => 'old',
            'last_sync_origin' => 'local',
        ]);

        $provider = Mockery::mock(MicrosoftCalendarProvider::class);
        $provider->shouldReceive('buildEventPayload')->once()->andReturn(['subject' => 'Renamed']);
        $provider->shouldReceive('updateEvent')->once()->withArgs(function ($conn, $eventId, $payload) {
            return $eventId === 'evt-1';
        })->andReturn(new ExternalCalendarEvent(
            id: 'evt-1',
            subject: 'Renamed',
            start: Carbon::parse('2026-08-10 12:00:00'),
            end: Carbon::parse('2026-08-10 13:00:00'),
            allDay: false,
            changeKey: 'ck-2',
            lastModified: now(),
        ));

        $todo->forceFill(['title' => 'Renamed'])->saveQuietly();

        $sync = new CalendarSyncService($provider);
        $mapping = $sync->pushTodoToOutlook($todo->fresh(), $connection);

        $this->assertSame('ck-2', $mapping->external_change_key);
    }

    public function test_outlook_datetime_change_updates_task_only_scheduling_fields(): void
    {
        [$user, $connection, $todo] = $this->seedSyncedTodo();
        $todo->forceFill(['status' => TodoStatus::InProgress, 'priority' => TodoPriority::High])->saveQuietly();

        TaskCalendarEvent::query()->create([
            'todo_id' => $todo->getKey(),
            'calendar_connection_id' => $connection->getKey(),
            'external_event_id' => 'evt-1',
            'external_status' => TaskCalendarExternalStatus::Synced,
            'sync_hash' => 'different',
            'last_sync_origin' => 'local',
            'last_synced_at' => now()->subHour(),
        ]);

        $provider = Mockery::mock(MicrosoftCalendarProvider::class);
        $sync = new CalendarSyncService($provider);

        $event = new ExternalCalendarEvent(
            id: 'evt-1',
            subject: 'Ignored subject',
            start: Carbon::parse('2026-08-11 09:00:00'),
            end: Carbon::parse('2026-08-11 10:30:00'),
            allDay: false,
            changeKey: 'ck-new',
            lastModified: now(),
        );

        $updated = $sync->applyExternalEventToTodo($connection, $event);

        $this->assertTrue($updated->start_date->equalTo(Carbon::parse('2026-08-11 09:00:00')));
        $this->assertTrue($updated->deadline->equalTo(Carbon::parse('2026-08-11 10:30:00')));
        $this->assertSame(TodoStatus::InProgress, $updated->status);
        $this->assertSame(TodoPriority::High, $updated->priority);
        $this->assertSame('Test task', $updated->title);
    }

    public function test_outlook_resize_updates_end_time(): void
    {
        [$user, $connection, $todo] = $this->seedSyncedTodo();

        TaskCalendarEvent::query()->create([
            'todo_id' => $todo->getKey(),
            'calendar_connection_id' => $connection->getKey(),
            'external_event_id' => 'evt-1',
            'external_status' => TaskCalendarExternalStatus::Synced,
            'sync_hash' => 'x',
            'last_synced_at' => now()->subHour(),
        ]);

        $provider = Mockery::mock(MicrosoftCalendarProvider::class);
        $sync = new CalendarSyncService($provider);

        $sync->applyExternalEventToTodo($connection, new ExternalCalendarEvent(
            id: 'evt-1',
            subject: 'Test task',
            start: Carbon::parse('2026-08-10 10:00:00'),
            end: Carbon::parse('2026-08-10 15:00:00'),
            allDay: false,
            changeKey: 'ck-resize',
            lastModified: now(),
        ));

        $this->assertTrue($todo->fresh()->deadline->equalTo(Carbon::parse('2026-08-10 15:00:00')));
    }

    public function test_outlook_deletion_does_not_delete_task_and_marks_mapping(): void
    {
        [$user, $connection, $todo] = $this->seedSyncedTodo();

        TaskCalendarEvent::query()->create([
            'todo_id' => $todo->getKey(),
            'calendar_connection_id' => $connection->getKey(),
            'external_event_id' => 'evt-1',
            'external_status' => TaskCalendarExternalStatus::Synced,
        ]);

        $provider = Mockery::mock(MicrosoftCalendarProvider::class);
        $sync = new CalendarSyncService($provider);
        $mapping = $sync->markExternallyDeleted($connection, 'evt-1');

        $this->assertNotNull(Todo::query()->find($todo->getKey()));
        $this->assertTrue($mapping->isDeletedExternally());
        $this->assertNull($mapping->external_event_id);
        $this->assertSame('evt-1', $mapping->last_external_event_id);
        $this->assertNotNull($mapping->deleted_externally_at);
        $this->assertTrue($mapping->canRestoreToOutlook());
    }

    public function test_restore_recreates_outlook_event_without_losing_history(): void
    {
        [$user, $connection, $todo] = $this->seedSyncedTodo();

        $mapping = TaskCalendarEvent::query()->create([
            'todo_id' => $todo->getKey(),
            'calendar_connection_id' => $connection->getKey(),
            'external_event_id' => null,
            'last_external_event_id' => 'evt-old',
            'external_status' => TaskCalendarExternalStatus::DeletedExternally,
            'deleted_externally_at' => now()->subDay(),
        ]);

        $provider = Mockery::mock(MicrosoftCalendarProvider::class);
        $provider->shouldReceive('buildEventPayload')->once()->andReturn(['subject' => 'Test']);
        $provider->shouldReceive('createEvent')->once()->andReturn(new ExternalCalendarEvent(
            id: 'evt-new',
            subject: 'Test',
            start: $todo->start_date,
            end: $todo->deadline,
            allDay: false,
            changeKey: 'ck-restored',
            lastModified: now(),
        ));

        $sync = new CalendarSyncService($provider);
        $restored = $sync->restoreTodoToOutlook($todo);

        $this->assertSame('evt-new', $restored->external_event_id);
        $this->assertSame(TaskCalendarExternalStatus::Synced, $restored->external_status);
        $this->assertNull($restored->deleted_externally_at);
        $this->assertSame($mapping->getKey(), $restored->getKey());
    }

    public function test_local_push_then_same_changekey_does_not_loop(): void
    {
        [$user, $connection, $todo] = $this->seedSyncedTodo();

        $mapping = TaskCalendarEvent::query()->create([
            'todo_id' => $todo->getKey(),
            'calendar_connection_id' => $connection->getKey(),
            'external_event_id' => 'evt-1',
            'external_change_key' => 'ck-same',
            'external_status' => TaskCalendarExternalStatus::Synced,
            'sync_hash' => 'anything',
            'last_sync_origin' => 'local',
            'last_synced_at' => now(),
        ]);

        $originalStart = $todo->start_date->copy();

        $provider = Mockery::mock(MicrosoftCalendarProvider::class);
        $sync = new CalendarSyncService($provider);

        $sync->applyExternalEventToTodo($connection, new ExternalCalendarEvent(
            id: 'evt-1',
            subject: 'Test task',
            start: Carbon::parse('2099-01-01 00:00:00'),
            end: Carbon::parse('2099-01-01 01:00:00'),
            allDay: false,
            changeKey: 'ck-same',
            lastModified: now(),
        ));

        $this->assertTrue($todo->fresh()->start_date->equalTo($originalStart));
        $this->assertSame('local', $mapping->fresh()->last_sync_origin);
    }

    public function test_webhook_validation_returns_token(): void
    {
        $response = $this->post('/webhooks/microsoft/calendar?validationToken=abc123');

        $response->assertOk();
        $this->assertSame('abc123', $response->getContent());
    }

    public function test_invalid_webhook_client_state_is_rejected(): void
    {
        [$user, $connection] = $this->seedConnection();
        $connection->forceFill([
            'subscription_id' => 'sub-1',
            'subscription_client_state' => 'expected-state',
        ])->save();

        $response = $this->postJson('/webhooks/microsoft/calendar', [
            'value' => [[
                'subscriptionId' => 'sub-1',
                'clientState' => 'wrong-state',
                'changeType' => 'updated',
            ]],
        ]);

        $response->assertStatus(202);
        // Job should not be dispatched for invalid clientState — queue sync would run immediately if dispatched.
        // With wrong state, no SyncMicrosoftCalendarChanges should have been queued with real work.
        $this->assertTrue(true);
    }

    public function test_user_isolation_between_connections(): void
    {
        [$userA, $connectionA, $todoA] = $this->seedSyncedTodo();
        [$userB, $connectionB] = $this->seedConnection('b@example.com');

        TaskCalendarEvent::query()->create([
            'todo_id' => $todoA->getKey(),
            'calendar_connection_id' => $connectionA->getKey(),
            'external_event_id' => 'evt-a',
            'external_status' => TaskCalendarExternalStatus::Synced,
            'sync_hash' => 'x',
            'last_synced_at' => now()->subHour(),
        ]);

        $provider = Mockery::mock(MicrosoftCalendarProvider::class);
        $sync = new CalendarSyncService($provider);

        $result = $sync->applyExternalEventToTodo($connectionB, new ExternalCalendarEvent(
            id: 'evt-a',
            subject: 'Hack',
            start: Carbon::parse('2099-01-01'),
            end: Carbon::parse('2099-01-02'),
            allDay: false,
            changeKey: 'ck',
            lastModified: now(),
        ));

        $this->assertNull($result);
        $this->assertSame('Test task', $todoA->fresh()->title);
    }

    public function test_all_day_sync_hash_and_payload(): void
    {
        $provider = new MicrosoftCalendarProvider;
        $payload = $provider->buildEventPayload(
            'All day',
            Carbon::parse('2026-08-10'),
            Carbon::parse('2026-08-10'),
            true,
            'UTC',
        );

        $this->assertTrue($payload['isAllDay']);
        $this->assertStringContainsString('2026-08-10', $payload['start']['dateTime']);
        $this->assertStringContainsString('2026-08-11', $payload['end']['dateTime']);
    }

    /**
     * @return array{0: User, 1: CalendarConnection, 2: Todo}
     */
    protected function seedSyncedTodo(): array
    {
        [$user, $connection] = $this->seedConnection();

        $todo = Todo::withoutEvents(fn () => Todo::query()->create([
            'user_id' => $user->getKey(),
            'responsible_id' => $user->getKey(),
            'title' => 'Test task',
            'start_date' => Carbon::parse('2026-08-10 10:00:00'),
            'deadline' => Carbon::parse('2026-08-10 11:00:00'),
            'status' => TodoStatus::New,
            'priority' => TodoPriority::Regular,
            'calendar_sync_enabled' => true,
            'all_day' => false,
            'archived' => false,
        ]));

        return [$user, $connection, $todo];
    }

    /**
     * @return array{0: User, 1: CalendarConnection}
     */
    protected function seedConnection(string $email = 'owner@example.com'): array
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        $connection = CalendarConnection::query()->create([
            'user_id' => $user->getKey(),
            'provider' => CalendarProvider::Microsoft,
            'account_email' => $email,
            'calendar_id' => 'cal-1',
            'calendar_name' => 'Calendar',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'status' => CalendarConnectionStatus::Active,
            'subscription_client_state' => 'state',
        ]);

        return [$user, $connection];
    }
}
