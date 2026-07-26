<?php

namespace App\Console\Commands;

use App\Enums\AgentName;
use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\ReviewType;
use App\Models\AgentConfig;
use App\Models\Review;
use App\Support\Agents\AgentRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('review:generate {--scheduled : Only used by the scheduler, not yet gated by day/time}')]
#[Description("Génère la revue via l'agent reviewer")]
class GenerateReviewCommand extends Command
{
    /**
     * Minimal end-to-end path for now: a fixed weekly period and a bare
     * prompt. Step 4 replaces the prompt/context here with the real
     * ReviewContextBuilder + resources/prompts/reviewer.md and adds the
     * scheduled day/time gating and the monthly variant.
     */
    public function handle(AgentRunner $runner): int
    {
        $config = AgentConfig::where('agent_name', AgentName::Reviewer->value)
            ->where('enabled', true)
            ->first();

        if (! $config) {
            $this->warn("Le reviewer n'est pas configuré.");

            return self::SUCCESS;
        }

        $trigger = $this->option('scheduled') ? AgentRunTrigger::Scheduled : AgentRunTrigger::Manual;

        $run = $runner->run(
            $config,
            "Tu es reviewer, l'agent qui génère la revue hebdomadaire de TARS.",
            [['role' => 'user', 'content' => 'Génère la revue de la semaine.']],
            $trigger,
        );

        if ($run->status !== AgentRunStatus::Success) {
            $this->error('Échec de la génération de la revue.');

            return self::FAILURE;
        }

        Review::create([
            'type' => ReviewType::Weekly,
            'period_start' => now()->subDays(7),
            'period_end' => now(),
            'generated_content' => $run->output,
        ]);

        $this->info('Revue générée.');

        return self::SUCCESS;
    }
}
