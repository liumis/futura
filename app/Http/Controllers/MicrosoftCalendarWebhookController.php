<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMicrosoftCalendarChanges;
use App\Models\CalendarConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MicrosoftCalendarWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Graph subscription validation handshake.
        if ($request->query->has('validationToken')) {
            return response((string) $request->query('validationToken'), 200)
                ->header('Content-Type', 'text/plain');
        }

        $payload = $request->json()->all();
        $notifications = $payload['value'] ?? [];

        if (! is_array($notifications)) {
            return response('ignored', 202);
        }

        $connectionIds = [];

        foreach ($notifications as $notification) {
            if (! is_array($notification)) {
                continue;
            }

            $clientState = (string) ($notification['clientState'] ?? '');
            $subscriptionId = (string) ($notification['subscriptionId'] ?? '');

            if ($clientState === '' || $subscriptionId === '') {
                Log::warning('Microsoft calendar webhook missing clientState/subscriptionId');
                continue;
            }

            $connection = CalendarConnection::query()
                ->where('subscription_id', $subscriptionId)
                ->first();

            if ($connection === null) {
                Log::warning('Microsoft calendar webhook for unknown subscription', [
                    'subscription_id' => $subscriptionId,
                ]);
                continue;
            }

            if (! hash_equals((string) $connection->subscription_client_state, $clientState)) {
                Log::warning('Microsoft calendar webhook clientState mismatch', [
                    'connection_id' => $connection->getKey(),
                ]);
                continue;
            }

            $connectionIds[$connection->getKey()] = true;
        }

        foreach (array_keys($connectionIds) as $connectionId) {
            SyncMicrosoftCalendarChanges::dispatch((int) $connectionId);
        }

        return response('accepted', 202);
    }
}
