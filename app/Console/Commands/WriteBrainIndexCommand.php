<?php

namespace App\Console\Commands;

use App\Support\Brain\BrainSettings;
use App\Support\Brain\IndexFileGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('brain:write-index {--scheduled : Skip silently when the vault is unconfigured}')]
#[Description('Repose le README du vault et régénère les _index.md des dossiers structurants')]
class WriteBrainIndexCommand extends Command
{
    public function handle(BrainSettings $settings, IndexFileGenerator $generator): int
    {
        if (! $settings->isConfigured()) {
            if (! $this->option('scheduled')) {
                $this->warn("Le vault n'est pas configuré — rien à écrire.");
            }

            return self::SUCCESS;
        }

        $result = $generator->write();

        if (! $result->isWritten()) {
            $this->error($result->message());

            return self::FAILURE;
        }

        $this->info($result->paths === []
            ? 'Index à jour — rien à réécrire.'
            : count($result->paths).' fichier(s) d\'index réécrit(s) : '.implode(', ', $result->paths).'.');

        return self::SUCCESS;
    }
}
