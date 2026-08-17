<?php

namespace App\Services\Calendar;

use App\Contracts\CalendarProviderInterface;
use App\Enums\CalendarConnectionStatus;
use App\Models\CalendarConnection;
use App\Models\SharepointSetting;
use App\Support\Calendar\ExternalCalendarEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class MicrosoftCalendarProvider implements CalendarProviderInterface
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    private const AUTH = 'https://login.microsoftonline.com';

    private const SCOPES = 'openid offline_access profile User.Read Calendars.ReadWrite';

    /**
     * @return array{tenant: string, client_id: string, client_secret: string, redirect: string}
     */
    public static function oauthConfig(): array
    {
        $tenant = (string) config('services.microsoft_calendar.tenant_id', '');
        $clientId = (string) config('services.microsoft_calendar.client_id', '');
        $clientSecret = (string) config('services.microsoft_calendar.client_secret', '');
        $redirect = (string) config('services.microsoft_calendar.redirect', '');

        // Fall back to SharePoint Entra app registration when calendar env is incomplete.
        if ($tenant === '' || $clientId === '' || $clientSecret === '') {
            $settings = SharepointSetting::instance();
            $tenant = $tenant !== '' ? $tenant : (string) ($settings->tenant_id ?? '');
            $clientId = $clientId !== '' ? $clientId : (string) ($settings->client_id ?? '');
            $clientSecret = $clientSecret !== '' ? $clientSecret : (string) ($settings->client_secret ?? '');
        }

        if ($redirect === '') {
            $redirect = url('/oauth/microsoft/calendar/callback');
        }

        return [
            'tenant' => $tenant !== '' ? $tenant : 'common',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect' => $redirect,
        ];
    }

    public static function isConfigured(): bool
    {
        $config = self::oauthConfig();

        return filled($config['client_id']) && filled($config['client_secret']);
    }

    public function authorizationUrl(string $state): string
    {
        $config = self::oauthConfig();
        if (! self::isConfigured()) {
            throw new RuntimeException('Microsoft Calendar OAuth is not configured.');
        }

        $query = http_build_query([
            'client_id' => $config['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $config['redirect'],
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return self::AUTH.'/'.$config['tenant'].'/oauth2/v2.0/authorize?'.$query;
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, account_email: ?string, external_account_id: ?string}
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        $config = self::oauthConfig();
        $response = Http::asForm()->post(self::AUTH.'/'.$config['tenant'].'/oauth2/v2.0/token', [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $config['redirect'],
            'scope' => self::SCOPES,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft OAuth token exchange failed: '.$response->body());
        }

        $json = $response->json();
        $accessToken = (string) ($json['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Microsoft OAuth response missing access_token.');
        }

        $profile = Http::withToken($accessToken)
            ->acceptJson()
            ->get(self::GRAPH.'/me')
            ->json();

        return [
            'access_token' => $accessToken,
            'refresh_token' => isset($json['refresh_token']) ? (string) $json['refresh_token'] : null,
            'expires_in' => (int) ($json['expires_in'] ?? 3600),
            'account_email' => $profile['mail'] ?? $profile['userPrincipalName'] ?? null,
            'external_account_id' => isset($profile['id']) ? (string) $profile['id'] : null,
        ];
    }

    public function refreshAccessToken(CalendarConnection $connection): CalendarConnection
    {
        if (! filled($connection->refresh_token)) {
            throw new RuntimeException('Microsoft calendar connection has no refresh token.');
        }

        $config = self::oauthConfig();
        $response = Http::asForm()->post(self::AUTH.'/'.$config['tenant'].'/oauth2/v2.0/token', [
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'scope' => self::SCOPES,
        ]);

        if (! $response->successful()) {
            $connection->forceFill([
                'status' => CalendarConnectionStatus::Error,
                'last_error' => 'Token refresh failed: HTTP '.$response->status(),
            ])->save();

            throw new RuntimeException('Microsoft token refresh failed.');
        }

        $json = $response->json();
        $connection->forceFill([
            'access_token' => (string) ($json['access_token'] ?? ''),
            'refresh_token' => filled($json['refresh_token'] ?? null)
                ? (string) $json['refresh_token']
                : $connection->refresh_token,
            'token_expires_at' => now()->addSeconds(max(60, (int) ($json['expires_in'] ?? 3600) - 120)),
            'status' => CalendarConnectionStatus::Active,
            'last_error' => null,
        ])->save();

        return $connection->fresh() ?? $connection;
    }

    public function ensureFreshToken(CalendarConnection $connection): CalendarConnection
    {
        if ($connection->needsTokenRefresh()) {
            return $this->refreshAccessToken($connection);
        }

        return $connection;
    }

    public function listCalendars(CalendarConnection $connection): array
    {
        $connection = $this->ensureFreshToken($connection);
        $response = $this->http($connection)->get(self::GRAPH.'/me/calendars', [
            '$select' => 'id,name,isDefaultCalendar',
            '$top' => 50,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to list calendars: HTTP '.$response->status());
        }

        $items = [];
        foreach ($response->json('value') ?? [] as $row) {
            if (! is_array($row) || blank($row['id'] ?? null)) {
                continue;
            }
            $items[] = [
                'id' => (string) $row['id'],
                'name' => (string) ($row['name'] ?? 'Calendar'),
                'is_default' => (bool) ($row['isDefaultCalendar'] ?? false),
            ];
        }

        return $items;
    }

    public function createEvent(CalendarConnection $connection, array $payload): ExternalCalendarEvent
    {
        $connection = $this->ensureFreshToken($connection);
        $calendarId = $this->requireCalendarId($connection);
        $response = $this->http($connection)
            ->post(self::GRAPH.'/me/calendars/'.rawurlencode($calendarId).'/events', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to create Outlook event: HTTP '.$response->status().' '.$response->body());
        }

        return ExternalCalendarEvent::fromMicrosoftGraph($response->json() ?? []);
    }

    public function updateEvent(CalendarConnection $connection, string $eventId, array $payload): ExternalCalendarEvent
    {
        $connection = $this->ensureFreshToken($connection);
        $response = $this->http($connection)
            ->patch(self::GRAPH.'/me/events/'.rawurlencode($eventId), $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to update Outlook event: HTTP '.$response->status().' '.$response->body());
        }

        return ExternalCalendarEvent::fromMicrosoftGraph($response->json() ?? []);
    }

    public function getEvent(CalendarConnection $connection, string $eventId): ?ExternalCalendarEvent
    {
        $connection = $this->ensureFreshToken($connection);
        $response = $this->http($connection)
            ->get(self::GRAPH.'/me/events/'.rawurlencode($eventId));

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch Outlook event: HTTP '.$response->status());
        }

        return ExternalCalendarEvent::fromMicrosoftGraph($response->json() ?? []);
    }

    public function deleteEvent(CalendarConnection $connection, string $eventId): void
    {
        $connection = $this->ensureFreshToken($connection);
        $response = $this->http($connection)
            ->delete(self::GRAPH.'/me/events/'.rawurlencode($eventId));

        if ($response->status() === 404) {
            return;
        }

        if (! $response->successful()) {
            throw new RuntimeException('Failed to delete Outlook event: HTTP '.$response->status());
        }
    }

    public function createSubscription(CalendarConnection $connection, string $notificationUrl, string $clientState): array
    {
        $connection = $this->ensureFreshToken($connection);
        $calendarId = $this->requireCalendarId($connection);
        $expiration = now()->addHours(48);

        $response = $this->http($connection)->post(self::GRAPH.'/subscriptions', [
            'changeType' => 'created,updated,deleted',
            'notificationUrl' => $notificationUrl,
            'resource' => '/me/calendars/'.$calendarId.'/events',
            'expirationDateTime' => $expiration->toIso8601String(),
            'clientState' => $clientState,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to create Graph subscription: HTTP '.$response->status().' '.$response->body());
        }

        return $response->json() ?? [];
    }

    public function renewSubscription(CalendarConnection $connection, string $subscriptionId): array
    {
        $connection = $this->ensureFreshToken($connection);
        $expiration = now()->addHours(48);

        $response = $this->http($connection)->patch(
            self::GRAPH.'/subscriptions/'.rawurlencode($subscriptionId),
            ['expirationDateTime' => $expiration->toIso8601String()],
        );

        if (! $response->successful()) {
            throw new RuntimeException('Failed to renew Graph subscription: HTTP '.$response->status().' '.$response->body());
        }

        return $response->json() ?? [];
    }

    public function deleteSubscription(CalendarConnection $connection, string $subscriptionId): void
    {
        try {
            $connection = $this->ensureFreshToken($connection);
            $this->http($connection)->delete(self::GRAPH.'/subscriptions/'.rawurlencode($subscriptionId));
        } catch (\Throwable $exception) {
            Log::warning('Failed deleting Graph calendar subscription', [
                'connection_id' => $connection->getKey(),
                'subscription_id' => $subscriptionId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function deltaSync(CalendarConnection $connection, ?string $deltaLink = null): array
    {
        $connection = $this->ensureFreshToken($connection);
        $calendarId = $this->requireCalendarId($connection);

        $url = $deltaLink ?: self::GRAPH.'/me/calendars/'.rawurlencode($calendarId).'/events/delta?$select=id,subject,start,end,isAllDay,changeKey,lastModifiedDateTime,iCalUId';

        $events = [];
        $deleted = [];
        $nextLink = $url;
        $newDelta = null;

        while (filled($nextLink)) {
            $response = $this->http($connection)->get($nextLink);
            if ($response->status() === 410) {
                // Delta token expired — restart without link.
                return $this->deltaSync($connection, null);
            }
            if (! $response->successful()) {
                throw new RuntimeException('Calendar delta sync failed: HTTP '.$response->status());
            }

            $json = $response->json() ?? [];
            foreach ($json['value'] ?? [] as $row) {
                if (! is_array($row) || blank($row['id'] ?? null)) {
                    continue;
                }
                if (($row['@removed']['reason'] ?? null) === 'deleted' || isset($row['@removed'])) {
                    $deleted[] = (string) $row['id'];
                    continue;
                }
                $events[] = ExternalCalendarEvent::fromMicrosoftGraph($row);
            }

            $nextLink = $json['@odata.nextLink'] ?? null;
            if (filled($json['@odata.deltaLink'] ?? null)) {
                $newDelta = (string) $json['@odata.deltaLink'];
                $nextLink = null;
            }
        }

        return [
            'events' => $events,
            'deleted_ids' => array_values(array_unique($deleted)),
            'delta_link' => $newDelta,
        ];
    }

    public function buildEventPayload(string $subject, $start, $end, bool $allDay, string $timeZone = 'UTC'): array
    {
        if ($allDay) {
            $startDate = \Illuminate\Support\Carbon::parse($start)->utc()->toDateString();
            $endDate = \Illuminate\Support\Carbon::parse($end)->utc()->addDay()->toDateString();

            return [
                'subject' => $subject,
                'isAllDay' => true,
                'start' => ['dateTime' => $startDate.'T00:00:00', 'timeZone' => $timeZone],
                'end' => ['dateTime' => $endDate.'T00:00:00', 'timeZone' => $timeZone],
            ];
        }

        return [
            'subject' => $subject,
            'isAllDay' => false,
            'start' => [
                'dateTime' => \Illuminate\Support\Carbon::parse($start)->utc()->format('Y-m-d\TH:i:s'),
                'timeZone' => $timeZone,
            ],
            'end' => [
                'dateTime' => \Illuminate\Support\Carbon::parse($end)->utc()->format('Y-m-d\TH:i:s'),
                'timeZone' => $timeZone,
            ],
        ];
    }

    protected function http(CalendarConnection $connection): PendingRequest
    {
        return Http::withToken((string) $connection->access_token)
            ->acceptJson()
            ->timeout(60);
    }

    protected function requireCalendarId(CalendarConnection $connection): string
    {
        if (! filled($connection->calendar_id)) {
            throw new RuntimeException('No Outlook calendar selected for this connection.');
        }

        return (string) $connection->calendar_id;
    }

    public static function newClientState(): string
    {
        return Str::random(40);
    }
}
