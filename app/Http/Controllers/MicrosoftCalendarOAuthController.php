<?php

namespace App\Http\Controllers;

use App\Enums\CalendarConnectionStatus;
use App\Enums\CalendarProvider;
use App\Jobs\RenewMicrosoftCalendarSubscriptions;
use App\Jobs\SyncMicrosoftCalendarChanges;
use App\Models\CalendarConnection;
use App\Services\Calendar\MicrosoftCalendarProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MicrosoftCalendarOAuthController extends Controller
{
    public function redirect(Request $request, MicrosoftCalendarProvider $provider): RedirectResponse
    {
        abort_unless($request->user() !== null, 403);
        abort_unless(MicrosoftCalendarProvider::isConfigured(), 503, 'Microsoft Calendar OAuth is not configured.');

        $state = Str::random(40);
        $request->session()->put('microsoft_calendar_oauth_state', $state);
        $request->session()->put('microsoft_calendar_oauth_user', $request->user()->getKey());

        return redirect()->away($provider->authorizationUrl($state));
    }

    public function callback(Request $request, MicrosoftCalendarProvider $provider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $expectedState = (string) $request->session()->pull('microsoft_calendar_oauth_state', '');
        $expectedUser = (int) $request->session()->pull('microsoft_calendar_oauth_user', 0);
        $state = (string) $request->query('state', '');

        if ($expectedState === '' || ! hash_equals($expectedState, $state) || $expectedUser !== (int) $user->getKey()) {
            return redirect('/outlook-calendar')
                ->with('error', 'Invalid OAuth state. Please try connecting again.');
        }

        if ($request->query->has('error')) {
            return redirect('/outlook-calendar')
                ->with('error', (string) $request->query('error_description', $request->query('error')));
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect('/outlook-calendar')->with('error', 'Missing authorization code.');
        }

        try {
            $tokens = $provider->exchangeAuthorizationCode($code);
            $connection = CalendarConnection::query()->updateOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'provider' => CalendarProvider::Microsoft,
                ],
                [
                    'external_account_id' => $tokens['external_account_id'],
                    'account_email' => $tokens['account_email'],
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_expires_at' => now()->addSeconds(max(60, $tokens['expires_in'] - 120)),
                    'status' => CalendarConnectionStatus::Active,
                    'last_error' => null,
                ],
            );

            // Prefer default calendar if none selected yet.
            if (! filled($connection->calendar_id)) {
                $calendars = $provider->listCalendars($connection);
                $default = collect($calendars)->firstWhere('is_default', true) ?? ($calendars[0] ?? null);
                if ($default !== null) {
                    $connection->forceFill([
                        'calendar_id' => $default['id'],
                        'calendar_name' => $default['name'],
                    ])->save();
                }
            }

            RenewMicrosoftCalendarSubscriptions::dispatch();
            SyncMicrosoftCalendarChanges::dispatch($connection->getKey());
        } catch (\Throwable $exception) {
            Log::error('Microsoft calendar OAuth callback failed', [
                'user_id' => $user->getKey(),
                'message' => $exception->getMessage(),
            ]);

            return redirect('/outlook-calendar')
                ->with('error', 'Could not connect Outlook: '.$exception->getMessage());
        }

        return redirect('/outlook-calendar')->with('success', 'Outlook Calendar connected.');
    }
}
