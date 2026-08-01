<?php

namespace App\Http\Controllers;

use App\Models\GoogleCalendarConnection;
use App\Support\GoogleCalendar\GoogleCalendarClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class GoogleCalendarOAuthController extends Controller
{
    public function redirect(GoogleCalendarClient $client): RedirectResponse
    {
        $state = Str::random(40);

        Session::put('google_calendar_oauth_state', $state);

        return redirect()->away($client->buildAuthorizationUrl($state));
    }

    public function callback(Request $request, GoogleCalendarClient $client): RedirectResponse
    {
        $state = Session::pull('google_calendar_oauth_state');

        if (! $request->filled('code') || $state === null || ! hash_equals($state, (string) $request->query('state'))) {
            return redirect()->route('settings.index')->with('status', 'google-calendar-failed');
        }

        $tokens = $client->exchangeCode((string) $request->query('code'));
        $existing = GoogleCalendarConnection::query()->first();

        $attributes = [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $existing?->refresh_token,
            'expires_at' => now()->addSeconds((int) $tokens['expires_in']),
            'needs_reconnect' => false,
        ];

        $connection = $existing !== null
            ? tap($existing)->update($attributes)
            : GoogleCalendarConnection::create($attributes);

        $connection->update([
            'google_account_email' => $client->fetchAccountEmail($connection->access_token),
        ]);

        return redirect()->route('settings.index')->with('status', 'google-calendar-connected');
    }
}
