<?php

namespace App\Console\Commands;

use App\Jobs\RefreshVaultStatusJob;
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

        // `pullRebase()` et non `pull()` : un `pull --ff-only` refuse dès qu'un
        // commit local existe, et comme Tars en crée lui-même à chaque
        // écriture, la moindre divergence condamnait la synchro à échouer à
        // l'identique pour toujours. C'est ce qui a gelé le vault pendant trois
        // mois. Le rebase est aussi ce que VaultWriter fait déjà avant chaque
        // écriture : une seule façon de rattraper le remote.
        $pull = $git->pullRebase($settings->localPath());

        if (! $pull->successful) {
            $settings->recordSyncFailure($pull->message);
            $this->error("Échec du pull : {$pull->message}");

            return self::FAILURE;
        }

        // Un remote injoignable, ou des modifications pas encore committées,
        // ne bloquent pas : le rebase n'a rien réécrit, et la raison remonte en
        // avertissement dans les Réglages.
        $settings->recordSyncWarning($pull->warning);
        $settings->clearSyncFailure();

        $settings->markSynced();

        // La synchro vient de parler au remote : c'est le moment le moins cher
        // pour constater l'état du vault, et ça évite qu'un écran ouvert pour
        // la première fois n'ait qu'un « état git inconnu » à montrer.
        dispatch_sync(new RefreshVaultStatusJob);

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
