<?php

namespace App\Console\Commands;

use App\Enums\AgentRunTrigger;
use App\Models\PlannerProposal;
use App\Support\Planner\PlannerAgent;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('planner:generate
    {--scheduled : Only fire at the configured day/time, and skip if already generated this week}
    {--start= : Period start date (Y-m-d) — manual runs only, requires --end}
    {--end= : Period end date (Y-m-d) — manual runs only, requires --start}'
)]
#[Description("Génère une proposition de planification via l'agent planner")]
class GeneratePlannerProposalCommand extends Command
{
    private const string SCHEDULED_TIME = '18:00';

    public function handle(PlannerAgent $plannerAgent): int
    {
        $scheduled = (bool) $this->option('scheduled');

        if ($scheduled) {
            $due = $this->dueScheduledRun();

            if ($due === null) {
                return self::SUCCESS;
            }

            [$periodStart, $periodEnd] = $due;
            $trigger = AgentRunTrigger::Scheduled;
        } else {
            [$periodStart, $periodEnd] = $this->manualPeriod();
            $trigger = AgentRunTrigger::Manual;
        }

        if (! $plannerAgent->isConfigured()) {
            if ($scheduled) {
                return self::SUCCESS;
            }

            $this->warn("Le planner n'est pas configuré.");

            return self::FAILURE;
        }

        $proposal = $plannerAgent->generate($periodStart, $periodEnd, $trigger);

        if (! $proposal) {
            $this->error('Échec de la génération de la proposition.');

            return self::FAILURE;
        }

        $this->info('Proposition générée.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}|null
     */
    private function dueScheduledRun(): ?array
    {
        $now = now();

        if ($now->isSunday() && $now->format('H:i') >= self::SCHEDULED_TIME) {
            $alreadyGenerated = PlannerProposal::whereDate('created_at', today())->exists();

            if (! $alreadyGenerated) {
                return $this->weekAheadPeriod();
            }
        }

        return null;
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function weekAheadPeriod(): array
    {
        return [now()->copy()->startOfDay(), now()->copy()->addDays(6)->endOfDay()];
    }

    /**
     * A manual run is free to cover whatever period the caller asks for
     * (--start/--end) — falls back to the usual "week ahead" default when
     * neither is given.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function manualPeriod(): array
    {
        $start = $this->option('start');
        $end = $this->option('end');

        if ($start && $end) {
            return [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()];
        }

        return $this->weekAheadPeriod();
    }
}
