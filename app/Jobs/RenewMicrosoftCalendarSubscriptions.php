<?php

namespace App\Jobs;

use App\Enums\CalendarConnectionStatus;
use App\Models\CalendarConnection;
use App\Services\Calendar\MicrosoftCalendarProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class RenewMicrosoftCalendarSubscriptions implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('calendar');
    }

    public function handle(MicrosoftCalendarProvider $provider): void
    {
        $connections = CalendarConnection::query()
            ->where('status', CalendarConnectionStatus::Active)
            ->whereNotNull('calendar_id')
            ->get();

        foreach ($connections as $connection) {
            try {
                $this->renewOrCreate($connection, $provider);
            } catch (Throwable $exception) {
                Log::error('Subscription renewal failed', [
                    'connection_id' => $connection->getKey(),
                    'message' => $exception->getMessage(),
                ]);
                $connection->forceFill([
                    'last_error' => $exception->getMessage(),
                ])->save();
            }
        }
    }

    protected function renewOrCreate(CalendarConnection $connection, MicrosoftCalendarProvider $provider): void
    {
        $notificationUrl = url('/webhooks/microsoft/calendar');

        if (filled($connection->subscription_id) && ! $connection->subscriptionNeedsRenewal()) {
            return;
        }

        if (filled($connection->subscription_id) && $connection->subscriptionNeedsRenewal()) {
            try {
                $result = $provider->renewSubscription($connection, (string) $connection->subscription_id);
                $connection->forceFill([
                    'subscription_expires_at' => filled($result['expirationDateTime'] ?? null)
                        ? Carbon::parse((string) $result['expirationDateTime'])
                        : now()->addHours(48),
                    'last_error' => null,
                ])->save();

                return;
            } catch (Throwable $exception) {
                Log::warning('Renew failed; recreating subscription', [
                    'connection_id' => $connection->getKey(),
                    'message' => $exception->getMessage(),
                ]);
                try {
                    $provider->deleteSubscription($connection, (string) $connection->subscription_id);
                } catch (Throwable) {
                }
            }
        }

        $clientState = $connection->subscription_client_state ?: MicrosoftCalendarProvider::newClientState();
        $result = $provider->createSubscription($connection, $notificationUrl, $clientState);

        $connection->forceFill([
            'subscription_id' => $result['id'] ?? null,
            'subscription_expires_at' => filled($result['expirationDateTime'] ?? null)
                ? Carbon::parse((string) $result['expirationDateTime'])
                : now()->addHours(48),
            'subscription_client_state' => $clientState,
            'last_error' => null,
        ])->save();
    }
}
