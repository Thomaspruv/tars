<?php

namespace App\Jobs;

use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes the vault after a machine write (§A.8). Retried three times, because
 * a transient network failure must not leave Thomas's second memory stuck on
 * the server.
 */
class PushVaultJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(BrainSettings $settings, GitRepository $git): void
    {
        if (! $settings->isConfigured() || ! $git->isRepository($settings->localPath())) {
            return;
        }

        try {
            $git->push($settings->localPath());
        } catch (\Throwable $e) {
            $settings->recordPushError($e->getMessage());

            throw $e;
        }

        $settings->recordPushError(null);
    }
}
