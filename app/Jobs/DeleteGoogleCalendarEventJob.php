<?php

namespace App\Jobs;

use App\Support\GoogleCalendar\GoogleCalendarSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteGoogleCalendarEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $googleEventId) {}

    public function handle(GoogleCalendarSyncService $sync): void
    {
        $sync->deleteEvent($this->googleEventId);
    }
}
