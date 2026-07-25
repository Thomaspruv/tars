<?php

namespace App\Console\Commands;

use App\Support\Brain\BrainSettings;
use App\Support\Brain\VaultIndexer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('brain:index {--fresh : Rebuild the index from scratch} {--scheduled : Skip silently when the vault is unconfigured or auto-index is disabled}')]
#[Description('Index the Obsidian vault into brain_documents')]
class IndexBrainCommand extends Command
{
    public function handle(BrainSettings $settings, VaultIndexer $indexer): int
    {
        $scheduled = (bool) $this->option('scheduled');

        if (! $settings->isConfigured()) {
            if (! $scheduled) {
                $this->warn("Le vault n'est pas configuré — rien à indexer.");
            }

            return self::SUCCESS;
        }

        if ($scheduled && ! $settings->autoIndexEnabled()) {
            return self::SUCCESS;
        }

        $summary = $indexer->indexAll(fresh: (bool) $this->option('fresh'));

        $this->info(sprintf(
            '%d créé(s), %d mis à jour, %d inchangé(s), %d supprimé(s).',
            $summary->created,
            $summary->updated,
            $summary->unchanged,
            $summary->deleted,
        ));

        return self::SUCCESS;
    }
}
