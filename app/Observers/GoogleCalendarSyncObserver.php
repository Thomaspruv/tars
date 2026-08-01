<?php

namespace App\Observers;

use App\Jobs\DeleteGoogleCalendarEventJob;
use App\Jobs\PushEventToGoogleCalendarJob;
use App\Models\Event;
use App\Models\GoogleCalendarConnection;

class GoogleCalendarSyncObserver
{
    public function created(Event $event): void
    {
        $this->push($event);
    }

    public function updated(Event $event): void
    {
        $this->push($event);
    }

    public function deleted(Event $event): void
    {
        if ($event->google_event_id !== null) {
            DeleteGoogleCalendarEventJob::dispatch($event->google_event_id);
        }
    }

    private function push(Event $event): void
    {
        if (GoogleCalendarConnection::query()->exists()) {
            PushEventToGoogleCalendarJob::dispatch($event->id);
        }
    }
}
