<?php

namespace App\Support\Review;

use App\Enums\AgentName;
use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\ReviewType;
use App\Models\AgentConfig;
use App\Models\Review;
use App\Support\Agents\AgentRunner;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;

class ReviewerAgent
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly ReviewContextBuilder $contextBuilder,
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

        return Review::create([
            'type' => $type,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'generated_content' => $markdown,
            'proposed_decisions' => $proposedDecisions,
        ]);
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
        /** @var list<array<string, mixed>> $decisions */
        $decisions = [];
        $markdown = trim($output);

        if (preg_match('/```json\s*(.*?)\s*```/s', $output, $matches)) {
            /** @var list<array<string, mixed>> $decisions */
            $decisions = json_decode($matches[1], true) ?? [];
            $markdown = trim(str_replace($matches[0], '', $output));
        }

        $proposedDecisions = [];

        foreach (array_slice($decisions, 0, 3) as $decision) {
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
