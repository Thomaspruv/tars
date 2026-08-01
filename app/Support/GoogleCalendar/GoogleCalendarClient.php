<?php

namespace App\Support\GoogleCalendar;

use App\Models\GoogleCalendarConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GoogleCalendarClient
{
    private const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const string REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const string USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private const string CALENDAR_BASE_URL = 'https://www.googleapis.com/calendar/v3';

    private const string SCOPE = 'https://www.googleapis.com/auth/calendar.events openid https://www.googleapis.com/auth/userinfo.email';

    public function buildAuthorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => route('google-calendar.callback'),
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'scope' => self::SCOPE,
            'state' => $state,
        ]);

        return self::AUTH_URL.'?'.$query;
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->client()->asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => route('google-calendar.callback'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        $response->throw();

        return $response->json();
    }

    public function fetchAccountEmail(string $accessToken): ?string
    {
        $response = $this->client()->withToken($accessToken)->get(self::USERINFO_URL);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('email');
    }

    public function revoke(string $token): void
    {
        $this->client()->asForm()->post(self::REVOKE_URL, ['token' => $token]);
    }

    /**
     * Returns a valid access token, refreshing it first if it has expired.
     */
    public function accessToken(GoogleCalendarConnection $connection): string
    {
        if (! $connection->isAccessTokenExpired()) {
            return $connection->access_token;
        }

        $response = $this->client()->asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            if ($response->json('error') === 'invalid_grant') {
                $connection->update(['needs_reconnect' => true]);
            }

            $response->throw();
        }

        $connection->update([
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in')),
        ]);

        return $connection->access_token;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createEvent(GoogleCalendarConnection $connection, array $payload): array
    {
        $response = $this->authorizedClient($connection)->post($this->eventsUrl($connection), $payload);

        $response->throw();

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateEvent(GoogleCalendarConnection $connection, string $googleEventId, array $payload): array
    {
        $response = $this->authorizedClient($connection)
            ->put($this->eventsUrl($connection).'/'.rawurlencode($googleEventId), $payload);

        if (in_array($response->status(), [404, 410], true)) {
            return $this->createEvent($connection, $payload);
        }

        $response->throw();

        return $response->json();
    }

    public function deleteEvent(GoogleCalendarConnection $connection, string $googleEventId): void
    {
        $response = $this->authorizedClient($connection)
            ->delete($this->eventsUrl($connection).'/'.rawurlencode($googleEventId));

        if (in_array($response->status(), [404, 410], true)) {
            return;
        }

        $response->throw();
    }

    /**
     * @return array{items: list<array<string, mixed>>, nextPageToken: ?string, nextSyncToken: ?string}
     *
     * @throws GoogleCalendarSyncTokenExpiredException
     */
    public function listEvents(GoogleCalendarConnection $connection, ?string $pageToken = null): array
    {
        $query = [
            'singleEvents' => 'true',
            'showDeleted' => 'true',
            'maxResults' => 250,
        ];

        if ($pageToken !== null) {
            $query['pageToken'] = $pageToken;
        }

        if ($connection->sync_token !== null) {
            $query['syncToken'] = $connection->sync_token;
        } else {
            $query['timeMin'] = now()->subMonths(3)->toRfc3339String();
            $query['timeMax'] = now()->addYear()->toRfc3339String();
        }

        $response = $this->authorizedClient($connection)->get($this->eventsUrl($connection), $query);

        if ($response->status() === 410) {
            throw new GoogleCalendarSyncTokenExpiredException;
        }

        $response->throw();

        return [
            'items' => $response->json('items', []),
            'nextPageToken' => $response->json('nextPageToken'),
            'nextSyncToken' => $response->json('nextSyncToken'),
        ];
    }

    private function eventsUrl(GoogleCalendarConnection $connection): string
    {
        return self::CALENDAR_BASE_URL.'/calendars/'.rawurlencode($connection->calendar_id).'/events';
    }

    private function authorizedClient(GoogleCalendarConnection $connection): PendingRequest
    {
        return $this->client()->withToken($this->accessToken($connection));
    }

    private function client(): PendingRequest
    {
        return Http::timeout(30);
    }
}
