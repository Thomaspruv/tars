<?php

namespace App\Support\Review;

use App\Enums\AgentName;
use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\ReviewType;
use App\Mail\ReviewGenerated;
use App\Models\AgentConfig;
use App\Models\Review;
use App\Support\Agents\AgentRunner;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

class ReviewerAgent
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly ReviewContextBuilder $contextBuilder,
        private readonly ReviewSettings $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->reviewerConfig() !== null;
    }

    public function generate(ReviewType $type, CarbonInterface $periodStart, CarbonInterface $periodEnd, AgentRunTrigger $trigger): ?Review
    {
        $config = $this->reviewerConfig();

        if (! $config) {
            return null;
        }

        $systemPrompt = File::get(resource_path('prompts/reviewer.md'));
        $context = $this->contextBuilder->build($periodStart, $periodEnd);

        $run = $this->runner->run($config, $systemPrompt, [['role' => 'user', 'content' => $context]], $trigger);

        if ($run->status !== AgentRunStatus::Success) {
            return null;
        }

        [$markdown, $proposedDecisions] = $this->parseOutput((string) $run->output);

        $review = Review::create([
            'type' => $type,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'generated_content' => $markdown,
            'proposed_decisions' => $proposedDecisions,
        ]);

        $this->notify($review);

        return $review;
    }

    private function notify(Review $review): void
    {
        $email = $this->settings->notificationEmail();

        if (! $this->settings->notificationsEnabled() || ! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new ReviewGenerated($review));
        } catch (\Throwable $e) {
            // The review was already generated and persisted successfully — a
            // mail transport failure must not make the whole run look failed.
            report($e);
        }
    }

    private function reviewerConfig(): ?AgentConfig
    {
        return AgentConfig::where('agent_name', AgentName::Reviewer->value)
            ->where('enabled', true)
            ->first();
    }

    /**
     * @return array{0: string, 1: list<array{question: string, goal: ?string, entity: ?string, response: ?string, answered_at: ?string}>}
     */
    private function parseOutput(string $output): array
    {
        /** @var list<mixed> $decisions */
        $decisions = [];
        $markdown = trim($output);

        if (preg_match('/```json\s*(.*?)\s*```/s', $output, $matches)) {
            $decoded = json_decode($matches[1], true);
            /** @var list<mixed> $decisions */
            $decisions = is_array($decoded) ? array_values($decoded) : [];
            $markdown = trim(str_replace($matches[0], '', $output));
        }

        $proposedDecisions = [];

        foreach (array_slice($decisions, 0, 3) as $decision) {
            if (! is_array($decision)) {
                continue;
            }

            $proposedDecisions[] = [
                'question' => (string) ($decision['question'] ?? ''),
                'goal' => isset($decision['goal']) ? (string) $decision['goal'] : null,
                'entity' => isset($decision['entity']) ? (string) $decision['entity'] : null,
                'response' => null,
                'answered_at' => null,
            ];
        }

        return [$markdown, $proposedDecisions];
    }
}
