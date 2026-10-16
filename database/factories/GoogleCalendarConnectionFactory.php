<?php

namespace Database\Factories;

use App\Models\GoogleCalendarConnection;
use App\Support\GoogleCalendar\GoogleCalendarClient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleCalendarConnection>
 */
class GoogleCalendarConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'access_token' => Str::random(40),
            'refresh_token' => Str::random(40),
            'expires_at' => now()->addHour(),
            'google_account_email' => fake()->safeEmail(),
            'granted_scopes' => [GoogleCalendarClient::CALENDAR_SCOPE, GoogleCalendarClient::GMAIL_READONLY_SCOPE],
            'calendar_id' => 'primary',
            'sync_token' => null,
            'last_synced_at' => null,
            'needs_reconnect' => false,
        ];
    }

    /**
     * A connection where only the calendar access was granted — either an old
     * connection predating the Gmail scope, or a consent screen where the
     * Gmail checkbox was left unticked.
     */
    public function withoutGmailAccess(): static
    {
        return $this->state(fn (): array => ['granted_scopes' => [GoogleCalendarClient::CALENDAR_SCOPE]]);
    }
}
