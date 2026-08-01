<?php

namespace Database\Factories;

use App\Models\GoogleCalendarConnection;
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
            'calendar_id' => 'primary',
            'sync_token' => null,
            'last_synced_at' => null,
            'needs_reconnect' => false,
        ];
    }
}
