<?php

namespace App\Console\Commands;

use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\Brain\VaultIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('brain:sync {--scheduled : Respect the configured sync frequency and auto-index toggle instead of running immediately}')]
#[Description('Pull the vault remote and reindex it')]
class SyncBrainCommand extends Command
{
    public function handle(BrainSettings $settings, GitRepository $git, VaultIndexer $indexer): int
    {
        $scheduled = (bool) $this->option('scheduled');

        if (! $settings->isConfigured()) {
            if (! $scheduled) {
                $this->warn("Le vault n'est pas configuré.");
            }

            return self::SUCCESS;
        }

        if ($scheduled && (! $settings->autoIndexEnabled() || ! $this->isDue($settings))) {
            return self::SUCCESS;
        }

        $git->ensureCloned($settings->remoteUrl(), $settings->branch(), $settings->localPath());

        $pull = $git->pull($settings->localPath());

        if (! $pull->successful) {
            $this->error("Échec du pull : {$pull->message}");

            return self::FAILURE;
        }

        $settings->markSynced();

        $summary = $indexer->indexAll();

        $this->info(sprintf(
            'Synchronisé — %d créé(s), %d mis à jour, %d inchangé(s), %d supprimé(s).',
            $summary->created,
            $summary->updated,
            $summary->unchanged,
            $summary->deleted,
        ));

        return self::SUCCESS;
    }

    private function isDue(BrainSettings $settings): bool
    {
        $lastSynced = $settings->lastSyncedAt();

        if ($lastSynced === null) {
            return true;
        }

        return $lastSynced->addMinutes($settings->syncFrequencyMinutes())->isPast();
    }
}
