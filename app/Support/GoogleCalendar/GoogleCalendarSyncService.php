<?php

namespace App\Support\GoogleCalendar;

use App\Models\Event;
use App\Models\GoogleCalendarConnection;
use Illuminate\Support\Carbon;

class GoogleCalendarSyncService
{
    public function __construct(private readonly GoogleCalendarClient $client) {}

    public function pushEvent(Event $event): void
    {
        $connection = GoogleCalendarConnection::query()->first();

        if ($connection === null) {
            return;
        }

        $payload = $this->buildPayload($event);

        $result = $event->google_event_id !== null
            ? $this->client->updateEvent($connection, $event->google_event_id, $payload)
            : $this->client->createEvent($connection, $payload);

        $event->forceFill([
            'google_event_id' => $result['id'],
            'google_synced_at' => now(),
        ]);
        $event->saveQuietly();
    }

    public function deleteEvent(string $googleEventId): void
    {
        $connection = GoogleCalendarConnection::query()->first();

        if ($connection === null) {
            return;
        }

        $this->client->deleteEvent($connection, $googleEventId);
    }

    /**
     * @return array{created: int, updated: int, deleted: int}
     */
    public function pull(): array
    {
        $connection = GoogleCalendarConnection::query()->first();

        if ($connection === null) {
            return ['created' => 0, 'updated' => 0, 'deleted' => 0];
        }

        try {
            $page = $this->fetchAllPages($connection);
        } catch (GoogleCalendarSyncTokenExpiredException) {
            $connection->update(['sync_token' => null]);
            $page = $this->fetchAllPages($connection);
        }

        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        Event::withoutEvents(function () use ($page, &$counts): void {
            foreach ($page['items'] as $item) {
                if (($item['status'] ?? null) === 'cancelled') {
                    if (Event::where('google_event_id', $item['id'])->delete()) {
                        $counts['deleted']++;
                    }

                    continue;
                }

                $existed = Event::where('google_event_id', $item['id'])->exists();
                $event = Event::firstOrNew(['google_event_id' => $item['id']]);

                [$startsAt, $endsAt] = $this->parseGoogleDates($item);

                $event->forceFill([
                    'title' => $item['summary'] ?? '(Sans titre)',
                    'notes' => $item['description'] ?? null,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'google_event_id' => $item['id'],
                    'google_synced_at' => now(),
                ]);
                $event->save();

                $existed ? $counts['updated']++ : $counts['created']++;
            }
        });

        $connection->update([
            'sync_token' => $page['nextSyncToken'],
            'last_synced_at' => now(),
        ]);

        return $counts;
    }

    /**
     * @return array{items: list<array<string, mixed>>, nextSyncToken: ?string}
     */
    private function fetchAllPages(GoogleCalendarConnection $connection): array
    {
        $items = [];
        $pageToken = null;
        $nextSyncToken = null;

        do {
            $page = $this->client->listEvents($connection, $pageToken);
            array_push($items, ...$page['items']);
            $pageToken = $page['nextPageToken'];
            $nextSyncToken = $page['nextSyncToken'] ?? $nextSyncToken;
        } while ($pageToken !== null);

        return ['items' => $items, 'nextSyncToken' => $nextSyncToken];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Event $event): array
    {
        $timezone = config('app.timezone');

        return [
            'summary' => $event->title,
            'description' => $event->notes,
            'start' => ['dateTime' => $event->starts_at->toRfc3339String(), 'timeZone' => $timezone],
            'end' => ['dateTime' => ($event->ends_at ?? $event->starts_at->copy()->addHour())->toRfc3339String(), 'timeZone' => $timezone],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: Carbon, 1: ?Carbon}
     */
    private function parseGoogleDates(array $item): array
    {
        if (isset($item['start']['date'])) {
            return [
                Carbon::parse($item['start']['date'])->startOfDay(),
                Carbon::parse($item['end']['date'])->subDay()->endOfDay(),
            ];
        }

        $timezone = config('app.timezone');

        return [
            Carbon::parse($item['start']['dateTime'])->setTimezone($timezone),
            isset($item['end']['dateTime']) ? Carbon::parse($item['end']['dateTime'])->setTimezone($timezone) : null,
        ];
    }
}
