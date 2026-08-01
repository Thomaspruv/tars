<?php

use App\Jobs\DeleteGoogleCalendarEventJob;
use App\Jobs\PushEventToGoogleCalendarJob;
use App\Models\Event;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('creating an event queues a push when a connection exists', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    GoogleCalendarConnection::factory()->create();

    $event = Event::factory()->create();

    Queue::assertPushed(PushEventToGoogleCalendarJob::class, fn ($job): bool => $job->eventId === $event->id);
});

test('creating an event does not queue a push without a connection', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    Event::factory()->create();

    Queue::assertNotPushed(PushEventToGoogleCalendarJob::class);
});

test('updating an event queues a push when a connection exists', function () {
    $event = Event::factory()->create();

    Queue::fake();
    $this->actingAs(User::factory()->create());
    GoogleCalendarConnection::factory()->create();

    $event->update(['title' => 'Nouveau titre']);

    Queue::assertPushed(PushEventToGoogleCalendarJob::class, fn ($job): bool => $job->eventId === $event->id);
});

test('deleting an event with a google_event_id queues a delete', function () {
    $event = Event::factory()->create();
    $event->forceFill(['google_event_id' => 'google-123'])->save();

    Queue::fake();
    $this->actingAs(User::factory()->create());

    $event->delete();

    Queue::assertPushed(DeleteGoogleCalendarEventJob::class, fn ($job): bool => $job->googleEventId === 'google-123');
});

test('deleting an event without a google_event_id does not queue a delete', function () {
    $event = Event::factory()->create();

    Queue::fake();
    $this->actingAs(User::factory()->create());

    $event->delete();

    Queue::assertNotPushed(DeleteGoogleCalendarEventJob::class);
});
