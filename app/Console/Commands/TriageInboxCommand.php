<?php

namespace App\Console\Commands;

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Support\Triage\TriageAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('triage:run {--scheduled : Skip silently instead of failing when triage is not configured}')]
#[Description("Classe les items en attente de l'inbox via l'agent de triage")]
class TriageInboxCommand extends Command
{
    public function handle(TriageAgent $triageAgent): int
    {
        $scheduled = (bool) $this->option('scheduled');

        if (! $triageAgent->isConfigured()) {
            if ($scheduled) {
                return self::SUCCESS;
            }

            $this->warn("Le triage n'est pas configuré.");

            return self::FAILURE;
        }

        $trigger = $scheduled ? AgentRunTrigger::Scheduled : AgentRunTrigger::Manual;
        $run = $triageAgent->process($trigger);

        if (! $run || $run->status !== AgentRunStatus::Success) {
            $this->error("Échec du triage de l'inbox.");

            return self::FAILURE;
        }

        $this->info('Inbox triée.');

        return self::SUCCESS;
    }
}
