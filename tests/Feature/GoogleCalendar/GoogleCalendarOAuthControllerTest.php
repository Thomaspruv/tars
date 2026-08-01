<?php

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

beforeEach(function () {
    config(['services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-client-secret']);
});

test('redirect stores a state token in session and includes it in the google url', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('google-calendar.connect'));

    $state = Session::get('google_calendar_oauth_state');

    expect($state)->not->toBeNull();
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('state='.$state);
});

test('callback exchanges the code and stores the connection', function () {
    $this->actingAs(User::factory()->create());
    Session::put('google_calendar_oauth_state', 'valid-state');

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'access-123',
            'refresh_token' => 'refresh-123',
            'expires_in' => 3600,
        ], 200),
        'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response(['email' => 'me@example.com'], 200),
    ]);

    $response = $this->get(route('google-calendar.callback', ['code' => 'auth-code', 'state' => 'valid-state']));

    $response->assertRedirect(route('settings.index'));
    $connection = GoogleCalendarConnection::query()->firstOrFail();
    expect($connection->access_token)->toBe('access-123')
        ->and($connection->refresh_token)->toBe('refresh-123')
        ->and($connection->google_account_email)->toBe('me@example.com');
});

test('callback aborts on a mismatched state without calling google', function () {
    $this->actingAs(User::factory()->create());
    Session::put('google_calendar_oauth_state', 'valid-state');

    Http::fake();

    $response = $this->get(route('google-calendar.callback', ['code' => 'auth-code', 'state' => 'wrong-state']));

    $response->assertRedirect(route('settings.index'));
    Http::assertNothingSent();
    expect(GoogleCalendarConnection::query()->exists())->toBeFalse();
});

test('callback aborts when no state was stored in session', function () {
    $this->actingAs(User::factory()->create());

    Http::fake();

    $response = $this->get(route('google-calendar.callback', ['code' => 'auth-code', 'state' => 'anything']));

    $response->assertRedirect(route('settings.index'));
    Http::assertNothingSent();
});
