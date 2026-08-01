<?php

namespace App\Jobs;

use App\Models\Event;
use App\Support\GoogleCalendar\GoogleCalendarSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PushEventToGoogleCalendarJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $eventId) {}

    public function handle(GoogleCalendarSyncService $sync): void
    {
        $event = Event::find($this->eventId);

        if ($event === null) {
            return;
        }

        $sync->pushEvent($event);
    }
}
