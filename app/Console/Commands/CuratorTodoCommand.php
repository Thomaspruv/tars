<?php

namespace App\Console\Commands;

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Support\Curator\CuratorAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('curator:todo {--scheduled : Skip silently instead of failing when the curator is not configured}')]
#[Description('Traite la todo du curateur — mission A, notes a-traiter du sas')]
class CuratorTodoCommand extends Command
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

        $trigger = $scheduled ? AgentRunTrigger::Scheduled : AgentRunTrigger::Manual;
        $run = $curatorAgent->process($trigger, 'todo');

        if (! $run || $run->status !== AgentRunStatus::Success) {
            $this->error('Échec du traitement de la todo.');

            return self::FAILURE;
        }

        $this->info('Todo traitée.');

        return self::SUCCESS;
    }
}
