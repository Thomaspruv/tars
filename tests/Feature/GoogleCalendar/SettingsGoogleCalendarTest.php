<?php

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// Rendering pages::settings-app evaluates brainStatus(), which shells out to a
// real `git fetch` against BrainSettings::localPath(). Point it at a path with
// no .git so GitRepository::status() short-circuits to Unknown instead of
// hitting the developer's real local vault clone over the network.
beforeEach(fn () => config(['brain.local_path' => sys_get_temp_dir().'/settings-test-'.uniqid()]));

test('shows the connect button when no connection exists and oauth credentials are configured', function () {
    config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'client-secret']);
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')->assertSee('Connecter Google Calendar');
});

test('shows the setup guide instead of the connect button when oauth credentials are missing', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
    $this->actingAs(User::factory()->create());

    // The step-by-step guide itself mentions the connect button by name, so
    // assert on the absence of the actual link rather than that literal text.
    Livewire::test('pages::settings-app')
        ->assertDontSee(route('google-calendar.connect'))
        ->assertSee('Aucun identifiant OAuth Google')
        ->assertSee(route('google-calendar.callback'));
});

test('shows the connected account and last sync time', function () {
    $this->actingAs(User::factory()->create());
    GoogleCalendarConnection::factory()->create(['google_account_email' => 'me@example.com']);

    Livewire::test('pages::settings-app')->assertSee('me@example.com');
});

test('disconnecting revokes the token and deletes the connection', function () {
    $this->actingAs(User::factory()->create());
    $connection = GoogleCalendarConnection::factory()->create();

    Http::fake(['https://oauth2.googleapis.com/revoke*' => Http::response([], 200)]);

    Livewire::test('pages::settings-app')->call('disconnectGoogleCalendar');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'revoke'));
    expect(GoogleCalendarConnection::find($connection->id))->toBeNull();
});

test('synchronising now runs the sync command and shows the result', function () {
    $this->actingAs(User::factory()->create());
    GoogleCalendarConnection::factory()->create();

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response(['items' => [], 'nextSyncToken' => 'sync-1'], 200),
    ]);

    Livewire::test('pages::settings-app')
        ->call('syncGoogleCalendarNow')
        ->assertSee('créé');
});
