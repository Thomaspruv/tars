<?php

namespace App\Support\Planner;

use App\Enums\AgentName;
use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\PlannerProposalStatus;
use App\Models\AgentConfig;
use App\Models\PlannerProposal;
use App\Models\PlannerProposalItem;
use App\Models\Task;
use App\Support\Agents\AgentRunner;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;

class PlannerAgent
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly PlannerContextBuilder $contextBuilder,
    ) {}

    public function isConfigured(): bool
    {
        return $this->plannerConfig() !== null;
    }

    public function generate(CarbonInterface $periodStart, CarbonInterface $periodEnd, AgentRunTrigger $trigger): ?PlannerProposal
    {
        $config = $this->plannerConfig();

        if (! $config) {
            return null;
        }

        $systemPrompt = File::get(resource_path('prompts/planner.md'));
        $context = $this->contextBuilder->build($periodStart, $periodEnd);

        $run = $this->runner->run($config, $systemPrompt, [['role' => 'user', 'content' => $context]], $trigger);

        if ($run->status !== AgentRunStatus::Success) {
            return null;
        }

        $items = $this->parseItems((string) $run->output);

        if ($items === null) {
            return null;
        }

        $proposal = PlannerProposal::create([
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => PlannerProposalStatus::Pending,
            'agent_run_id' => $run->id,
        ]);

        foreach ($items as $item) {
            $this->processItem($item, $proposal);
        }

        return $proposal->fresh(['items']);
    }

    /**
     * @return list<mixed>|null
     */
    private function parseItems(string $output): ?array
    {
        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded) && preg_match('/```json\s*(.*?)\s*```/s', $output, $matches)) {
            $decoded = json_decode($matches[1], true);
        }

        if (! is_array($decoded)) {
            return null;
        }

        return array_values($decoded);
    }

    private function processItem(mixed $item, PlannerProposal $proposal): void
    {
        if (! is_array($item)) {
            return;
        }

        $taskId = $this->parseTaskId($item['task_id'] ?? null);

        if ($taskId === null || ! is_string($item['proposed_scheduled_date'] ?? null)) {
            return;
        }

        $task = Task::open()->find($taskId);

        if (! $task) {
            return;
        }

        PlannerProposalItem::create([
            'planner_proposal_id' => $proposal->id,
            'task_id' => $task->id,
            'proposed_scheduled_date' => $item['proposed_scheduled_date'],
            'reason' => (string) ($item['reason'] ?? ''),
            'status' => PlannerProposalStatus::Pending,
        ]);
    }

    private function parseTaskId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function plannerConfig(): ?AgentConfig
    {
        return AgentConfig::where('agent_name', AgentName::Planner->value)
            ->where('enabled', true)
            ->first();
    }
}
