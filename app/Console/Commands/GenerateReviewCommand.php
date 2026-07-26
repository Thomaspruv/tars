<?php

namespace App\Console\Commands;

use App\Enums\AgentRunTrigger;
use App\Enums\ReviewType;
use App\Models\Review;
use App\Support\Review\ReviewerAgent;
use App\Support\Review\ReviewSettings;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('review:generate {--scheduled : Only fire at the configured day/time, and skip if already generated today}')]
#[Description("Génère la revue via l'agent reviewer")]
class GenerateReviewCommand extends Command
{
    public function handle(ReviewerAgent $reviewerAgent, ReviewSettings $settings): int
    {
        $scheduled = (bool) $this->option('scheduled');

        if ($scheduled) {
            $due = $this->dueScheduledRun($settings);

            if ($due === null) {
                return self::SUCCESS;
            }

            [$type, $periodStart, $periodEnd] = $due;
            $trigger = AgentRunTrigger::Scheduled;
        } else {
            $type = ReviewType::Weekly;
            [$periodStart, $periodEnd] = $this->weeklyPeriod();
            $trigger = AgentRunTrigger::Manual;
        }

        if (! $reviewerAgent->isConfigured()) {
            if (! $scheduled) {
                $this->warn("Le reviewer n'est pas configuré.");
            }

            return self::SUCCESS;
        }

        $review = $reviewerAgent->generate($type, $periodStart, $periodEnd, $trigger);

        if (! $review) {
            $this->error('Échec de la génération de la revue.');

            return self::FAILURE;
        }

        $this->info('Revue générée.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: ReviewType, 1: CarbonInterface, 2: CarbonInterface}|null
     */
    private function dueScheduledRun(ReviewSettings $settings): ?array
    {
        $now = now();

        if ($now->day === 1 && $now->format('H:i') >= $settings->weeklyTime()) {
            $alreadyGenerated = Review::where('type', ReviewType::Monthly)
                ->whereDate('created_at', today())
                ->exists();

            if (! $alreadyGenerated) {
                $periodStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
                $periodEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

                return [ReviewType::Monthly, $periodStart, $periodEnd];
            }
        }

        if ($now->isSunday() && $now->format('H:i') >= $settings->weeklyTime()) {
            $alreadyGenerated = Review::where('type', ReviewType::Weekly)
                ->whereDate('created_at', today())
                ->exists();

            if (! $alreadyGenerated) {
                [$periodStart, $periodEnd] = $this->weeklyPeriod();

                return [ReviewType::Weekly, $periodStart, $periodEnd];
            }
        }

        return null;
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function weeklyPeriod(): array
    {
        $lastWeekly = Review::where('type', ReviewType::Weekly)->latest('period_end')->first();

        $periodStart = $lastWeekly ? $lastWeekly->period_end->copy()->addDay() : now()->copy()->subDays(7);

        return [$periodStart, now()->copy()];
    }
}
