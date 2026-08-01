<?php

namespace App\Console\Commands;

use App\Support\GoogleCalendar\GoogleCalendarSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('google-calendar:sync {--scheduled}')]
#[Description('Importe les événements créés ou modifiés côté Google Calendar')]
class SyncGoogleCalendarCommand extends Command
{
    public function handle(GoogleCalendarSyncService $sync): int
    {
        try {
            $result = $sync->pull();
        } catch (\Throwable $e) {
            report($e);
            $this->error('Échec de la synchronisation Google Calendar : '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('scheduled')) {
            $this->info("{$result['created']} créé(s), {$result['updated']} mis à jour, {$result['deleted']} supprimé(s).");
        }

        return self::SUCCESS;
    }
}
