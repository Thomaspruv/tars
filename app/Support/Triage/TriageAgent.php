<?php

namespace App\Support\Triage;

use App\Enums\AgentName;
use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\InboxConvertedType;
use App\Enums\InboxSuggestionStatus;
use App\Enums\SuggestionConfidence;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Task;
use App\Support\Agents\AgentRunner;
use App\Support\FuzzyMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class TriageAgent
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly TriageContextBuilder $contextBuilder,
        private readonly InboxSuggestionApplier $applier,
        private readonly FuzzyMatcher $fuzzyMatcher,
    ) {}

    public function isConfigured(): bool
    {
        return $this->triageConfig() !== null;
    }

    public function process(AgentRunTrigger $trigger): ?AgentRun
    {
        $config = $this->triageConfig();

        if (! $config) {
            return null;
        }

        $systemPrompt = File::get(resource_path('prompts/triage.md'));
        $items = $this->contextBuilder->pendingItems();
        $context = $this->contextBuilder->build();

        $run = $this->runner->run($config, $systemPrompt, [['role' => 'user', 'content' => $context]], $trigger);

        if ($run->status !== AgentRunStatus::Success) {
            return $run;
        }

        $decoded = $this->decodeAndValidate((string) $run->output);

        if ($decoded === null) {
            $run->update(['status' => AgentRunStatus::Failed, 'error' => 'Réponse du triage invalide : JSON attendu, schéma non respecté.']);

            return $run->fresh();
        }

        $itemsById = $items->keyBy('id');

        foreach ($decoded as $item) {
            $this->processItem($item, $itemsById, $run);
        }

        return $run->fresh();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function decodeAndValidate(string $output): ?array
    {
        $decoded = json_decode(trim($output), true);

        if (! is_array($decoded) && preg_match('/```json\s*(.*?)\s*```/s', $output, $matches)) {
            $decoded = json_decode($matches[1], true);
        }

        if (! is_array($decoded)) {
            return null;
        }

        $items = array_values($decoded);
        $validTypes = array_map(fn (InboxConvertedType $case): string => $case->value, InboxConvertedType::cases());

        foreach ($items as $item) {
            if (! is_array($item) || ! is_int($item['inbox_item_id'] ?? null) || ! is_string($item['suggested_type'] ?? null)) {
                return null;
            }

            if (! in_array($item['suggested_type'], [...$validTypes, 'none'], true)) {
                return null;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, InboxItem>  $itemsById
     */
    private function processItem(array $item, Collection $itemsById, AgentRun $run): void
    {
        if ($item['suggested_type'] === 'none') {
            return;
        }

        $inboxItem = $itemsById->get($item['inbox_item_id']);

        if (! $inboxItem) {
            report(new \RuntimeException("Suggestion de triage ignorée : item inconnu #{$item['inbox_item_id']}."));

            return;
        }

        $type = InboxConvertedType::from($item['suggested_type']);

        if ($this->isRecentlyRejected($inboxItem->id, $type)) {
            return;
        }

        $suggestedFields = is_array($item['suggested_fields'] ?? null) ? $item['suggested_fields'] : [];
        $confidence = SuggestionConfidence::tryFrom((string) ($item['confidence'] ?? '')) ?? SuggestionConfidence::Medium;
        $reason = (string) ($item['reason'] ?? '');

        $suggestion = InboxSuggestion::create([
            'inbox_item_id' => $inboxItem->id,
            'suggested_type' => $type,
            'suggested_fields' => $suggestedFields,
            'confidence' => $confidence,
            'reason' => $reason,
            'agent_run_id' => $run->id,
            'status' => InboxSuggestionStatus::Pending,
        ]);

        if ($confidence === SuggestionConfidence::High && $this->hasNoConflict($type, $suggestedFields)) {
            try {
                $this->applier->apply($suggestion);
                $suggestion->update(['status' => InboxSuggestionStatus::AutoApplied]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $suggestedFields
     */
    private function hasNoConflict(InboxConvertedType $type, array $suggestedFields): bool
    {
        return match ($type) {
            InboxConvertedType::Task => $this->fuzzyMatcher->bestMatch(
                Task::open()->get(),
                trim((string) ($suggestedFields['title'] ?? '')),
                fn (Task $task): string => $task->title
            ) === null,
            InboxConvertedType::Goal => $this->fuzzyMatcher->bestMatch(
                Goal::all(),
                trim((string) ($suggestedFields['title'] ?? '')),
                fn (Goal $goal): string => $goal->title
            ) === null,
            InboxConvertedType::Event => $this->fuzzyMatcher->bestMatch(
                Event::where('starts_at', '>=', now())->get(),
                trim((string) ($suggestedFields['title'] ?? '')),
                fn (Event $event): string => $event->title
            ) === null,
            InboxConvertedType::Note => true,
        };
    }

    private function isRecentlyRejected(int $inboxItemId, InboxConvertedType $type): bool
    {
        return InboxSuggestion::where('inbox_item_id', $inboxItemId)
            ->where('suggested_type', $type->value)
            ->where('status', InboxSuggestionStatus::Rejected->value)
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();
    }

    private function triageConfig(): ?AgentConfig
    {
        return AgentConfig::where('agent_name', AgentName::Triage->value)
            ->where('enabled', true)
            ->first();
    }
}
