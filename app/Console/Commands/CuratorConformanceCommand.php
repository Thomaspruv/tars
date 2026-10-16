<?php

namespace App\Console\Commands;

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Models\AgentRun;
use App\Support\Curator\CuratorAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('curator:conformance {--scheduled : Skip silently instead of failing when the curator is not configured} {--limit= : Maximum number of documents to submit}')]
#[Description("Vérifie la conformité du cerveau — mission C : complétion, déplacement, fusion, archivage de l'existant, par lots que Thomas valide")]
class CuratorConformanceCommand extends Command
{
    public function handle(CuratorAgent $curatorAgent): int
    {
        $scheduled = (bool) $this->option('scheduled');

        if (! $curatorAgent->isConfigured()) {
            if ($scheduled) {
                return self::SUCCESS;
            }

            $this->warn("Le curateur n'est pas configuré.");

            return self::FAILURE;
        }

        $limit = $this->option('limit');

        $runs = $curatorAgent->processConformance(
            $scheduled ? AgentRunTrigger::Scheduled : AgentRunTrigger::Manual,
            $limit !== null ? max(1, (int) $limit) : null,
        );

        if ($runs->isEmpty()) {
            $this->info('Rien à mettre en conformité.');

            return self::SUCCESS;
        }

        $failed = $runs->filter(fn (AgentRun $run): bool => $run->status !== AgentRunStatus::Success);

        if ($failed->isNotEmpty()) {
            $this->error("{$failed->count()} lot(s) sur {$runs->count()} en échec.");

            return self::FAILURE;
        }

        $this->info("{$runs->count()} lot(s) analysé(s).");

        return self::SUCCESS;
    }
}
