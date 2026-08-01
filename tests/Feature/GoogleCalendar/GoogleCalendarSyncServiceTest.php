<?php

use App\Jobs\PushEventToGoogleCalendarJob;
use App\Models\Event;
use App\Models\GoogleCalendarConnection;
use App\Support\GoogleCalendar\GoogleCalendarSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('pushEvent creates a new google event and stores its id', function () {
    Queue::fake();
    GoogleCalendarConnection::factory()->create();
    $event = Event::factory()->create(['title' => 'Rendez-vous', 'notes' => 'Détails', 'ends_at' => null]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'google-abc'], 200),
    ]);

    app(GoogleCalendarSyncService::class)->pushEvent($event);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request['summary'] === 'Rendez-vous'
        && $request['description'] === 'Détails'
        && $request['start']['dateTime'] === $event->starts_at->toRfc3339String());

    expect($event->fresh()->google_event_id)->toBe('google-abc');
});

test('pushEvent updates an existing google event', function () {
    Queue::fake();
    GoogleCalendarConnection::factory()->create();
    $event = Event::factory()->create();
    $event->forceFill(['google_event_id' => 'existing-id'])->save();

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events/existing-id' => Http::response(['id' => 'existing-id'], 200),
    ]);

    app(GoogleCalendarSyncService::class)->pushEvent($event);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT');
    expect($event->fresh()->google_event_id)->toBe('existing-id');
});

test('pushEvent falls back to creating when the google event was deleted upstream', function () {
    Queue::fake();
    GoogleCalendarConnection::factory()->create();
    $event = Event::factory()->create();
    $event->forceFill(['google_event_id' => 'stale-id'])->save();

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events/stale-id' => Http::response([], 404),
        'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'brand-new-id'], 200),
    ]);

    app(GoogleCalendarSyncService::class)->pushEvent($event);

    expect($event->fresh()->google_event_id)->toBe('brand-new-id');
});

test('pushEvent saving the sync metadata does not re-trigger a push', function () {
    Queue::fake();
    GoogleCalendarConnection::factory()->create();
    $event = Event::factory()->create();

    Http::fake(['*' => Http::response(['id' => 'google-abc'], 200)]);

    app(GoogleCalendarSyncService::class)->pushEvent($event);

    // Exactly one push: the one from Event::factory()->create() above. The
    // explicit pushEvent() call's internal saveQuietly() must not add a second.
    Queue::assertPushed(PushEventToGoogleCalendarJob::class, 1);
});

test('pull creates local events, paginating and storing the final sync token', function () {
    $connection = GoogleCalendarConnection::factory()->create(['sync_token' => null]);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::sequence()
            ->push([
                'items' => [
                    ['id' => 'g1', 'status' => 'confirmed', 'summary' => 'Event 1', 'start' => ['dateTime' => '2026-08-05T10:00:00+00:00'], 'end' => ['dateTime' => '2026-08-05T11:00:00+00:00']],
                ],
                'nextPageToken' => 'page2',
            ], 200)
            ->push([
                'items' => [
                    ['id' => 'g2', 'status' => 'confirmed', 'summary' => 'Event 2', 'start' => ['dateTime' => '2026-08-06T10:00:00+00:00'], 'end' => ['dateTime' => '2026-08-06T11:00:00+00:00']],
                ],
                'nextSyncToken' => 'sync-final',
            ], 200),
    ]);

    $result = app(GoogleCalendarSyncService::class)->pull();

    expect($result)->toBe(['created' => 2, 'updated' => 0, 'deleted' => 0])
        ->and(Event::where('google_event_id', 'g1')->exists())->toBeTrue()
        ->and(Event::where('google_event_id', 'g2')->exists())->toBeTrue()
        ->and($connection->fresh()->sync_token)->toBe('sync-final');
});

test('pull deletes the local event when google reports it cancelled', function () {
    Queue::fake();
    GoogleCalendarConnection::factory()->create();
    $event = Event::factory()->create();
    $event->forceFill(['google_event_id' => 'g1'])->save();

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [['id' => 'g1', 'status' => 'cancelled']],
            'nextSyncToken' => 'sync-1',
        ], 200),
    ]);

    $result = app(GoogleCalendarSyncService::class)->pull();

    expect($result['deleted'])->toBe(1)
        ->and(Event::find($event->id))->toBeNull();
});

test('pull retries as a full sync when the stored sync token is rejected', function () {
    $connection = GoogleCalendarConnection::factory()->create(['sync_token' => 'stale-token']);

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::sequence()
            ->push([], 410)
            ->push(['items' => [], 'nextSyncToken' => 'fresh-token'], 200),
    ]);

    app(GoogleCalendarSyncService::class)->pull();

    expect($connection->fresh()->sync_token)->toBe('fresh-token');
});

test('pull converts an all-day google event to a full local day, undoing the exclusive end date', function () {
    GoogleCalendarConnection::factory()->create();

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [['id' => 'g1', 'summary' => 'Jour férié', 'start' => ['date' => '2026-08-15'], 'end' => ['date' => '2026-08-16']]],
            'nextSyncToken' => 'sync-1',
        ], 200),
    ]);

    app(GoogleCalendarSyncService::class)->pull();

    $event = Event::where('google_event_id', 'g1')->firstOrFail();
    expect($event->starts_at->toDateString())->toBe('2026-08-15')
        ->and($event->ends_at->toDateString())->toBe('2026-08-15');
});

test('pull does not trigger a push back to google', function () {
    GoogleCalendarConnection::factory()->create();

    Queue::fake();

    Http::fake([
        'https://www.googleapis.com/calendar/v3/calendars/primary/events*' => Http::response([
            'items' => [['id' => 'g1', 'summary' => 'Event', 'start' => ['dateTime' => '2026-08-05T10:00:00+00:00'], 'end' => ['dateTime' => '2026-08-05T11:00:00+00:00']]],
            'nextSyncToken' => 'sync-1',
        ], 200),
    ]);

    app(GoogleCalendarSyncService::class)->pull();

    Queue::assertNotPushed(PushEventToGoogleCalendarJob::class);
});

test('pull is a no-op without a connection', function () {
    Http::fake();

    $result = app(GoogleCalendarSyncService::class)->pull();

    expect($result)->toBe(['created' => 0, 'updated' => 0, 'deleted' => 0]);
    Http::assertNothingSent();
});
