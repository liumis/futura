<?php

namespace App\Contracts;

use App\Models\CalendarConnection;
use App\Support\Calendar\ExternalCalendarEvent;
use Carbon\CarbonInterface;

interface CalendarProviderInterface
{
    /**
     * @return list<array{id: string, name: string, is_default: bool}>
     */
    public function listCalendars(CalendarConnection $connection): array;

    public function createEvent(CalendarConnection $connection, array $payload): ExternalCalendarEvent;

    public function updateEvent(CalendarConnection $connection, string $eventId, array $payload): ExternalCalendarEvent;

    public function getEvent(CalendarConnection $connection, string $eventId): ?ExternalCalendarEvent;

    public function deleteEvent(CalendarConnection $connection, string $eventId): void;

    public function createSubscription(CalendarConnection $connection, string $notificationUrl, string $clientState): array;

    public function renewSubscription(CalendarConnection $connection, string $subscriptionId): array;

    public function deleteSubscription(CalendarConnection $connection, string $subscriptionId): void;

    /**
     * @return array{events: list<ExternalCalendarEvent>, deleted_ids: list<string>, delta_link: ?string}
     */
    public function deltaSync(CalendarConnection $connection, ?string $deltaLink = null): array;
}
