<?php

namespace App\Support\Curator;

use App\Enums\AgentName;
use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\BrainSuggestionAction;
use App\Enums\BrainSuggestionStatus;
use App\Enums\SuggestionConfidence;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Task;
use App\Support\Agents\AgentRunner;
use App\Support\Brain\AnchorResolver;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\FuzzyMatcher;
use App\Support\QuickAdd\QuickAddParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class CuratorAgent
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly CuratorContextBuilder $contextBuilder,
        private readonly AnchorResolver $anchorResolver,
        private readonly FuzzyMatcher $fuzzyMatcher,
        private readonly BrainSettings $settings,
        private readonly GitRepository $git,
    ) {}

    public function isConfigured(): bool
    {
        return $this->curatorConfig() !== null;
    }

    public function process(AgentRunTrigger $trigger, string $mission): ?AgentRun
    {
        $config = $this->curatorConfig();

        if (! $config) {
            return null;
        }

        $systemPrompt = File::get(resource_path('prompts/curator.md'));
        $notes = $this->contextBuilder->notesToProcess($mission);
        $context = $this->contextBuilder->build($mission);

        $run = $this->runner->run($config, $systemPrompt, [['role' => 'user', 'content' => $context]], $trigger);

        if ($run->status !== AgentRunStatus::Success) {
            return $run;
        }

        $items = $this->decodeAndValidate((string) $run->output);

        if ($items === null) {
            $run->update(['status' => AgentRunStatus::Failed, 'error' => 'Réponse du curateur invalide : JSON attendu, schéma non respecté.']);

            return $run->fresh();
        }

        $notesByPath = $notes->keyBy('path');

        foreach ($items as $item) {
            $this->processItem($item, $notesByPath, $mission, $run);
        }

        if ($mission === 'todo') {
            foreach ($notes as $note) {
                $this->markProcessed($note);
            }
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
        $validActions = array_map(fn (BrainSuggestionAction $case): string => $case->value, BrainSuggestionAction::cases());

        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['path'] ?? null) || ! is_string($item['action'] ?? null)) {
                return null;
            }

            if (! in_array($item['action'], [...$validActions, 'none'], true)) {
                return null;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<string, BrainDocument>  $notesByPath
     */
    private function processItem(array $item, Collection $notesByPath, string $mission, AgentRun $run): void
    {
        if ($item['action'] === 'none') {
            return;
        }

        $document = $notesByPath->get($item['path']);

        if (! $document) {
            report(new \RuntimeException("Suggestion du curateur ignorée : chemin inconnu « {$item['path']} »."));

            return;
        }

        $action = BrainSuggestionAction::from($item['action']);

        if ($this->isOutOfScope($document->path, $action)) {
            report(new \RuntimeException("Suggestion du curateur ignorée : chemin hors périmètre « {$document->path} » ({$action->value})."));

            return;
        }

        $target = is_string($item['target'] ?? null) ? $item['target'] : null;

        if ($this->isRecentlyRejected($document->id, $action, $target)) {
            return;
        }

        $frontmatterPatch = is_array($item['frontmatter'] ?? null) ? $item['frontmatter'] : null;
        $mergedContent = isset($item['merged_content']) ? (string) $item['merged_content'] : null;
        $confidence = SuggestionConfidence::tryFrom((string) ($item['confidence'] ?? '')) ?? SuggestionConfidence::Medium;
        $reason = (string) ($item['reason'] ?? '');

        $data = [
            'brain_document_id' => $document->id,
            'action' => $action,
            'target' => $target,
            'frontmatter_patch' => $frontmatterPatch,
            'merged_content' => $mergedContent,
            'confidence' => $confidence,
            'reason' => $reason,
            'agent_run_id' => $run->id,
        ];

        if ($mission === 'todo' && $action->isAutoApplicable() && $confidence === SuggestionConfidence::High) {
            $created = $this->tryAutoApply($action, $frontmatterPatch, $target);

            if ($created) {
                BrainSuggestion::create([
                    ...$data,
                    'status' => BrainSuggestionStatus::AutoApplied,
                    'created_type' => $created::class,
                    'created_id' => $created->getKey(),
                ]);

                return;
            }
        }

        BrainSuggestion::create([...$data, 'status' => BrainSuggestionStatus::Pending]);
    }

    private function isOutOfScope(string $path, BrainSuggestionAction $action): bool
    {
        if (! CuratorContextBuilder::isManagedPath($path)) {
            return true;
        }

        $topFolder = explode('/', $path)[0];

        return $topFolder === 'Profil' && ! in_array($action, [BrainSuggestionAction::Anchor, BrainSuggestionAction::Complete], true);
    }

    private function isRecentlyRejected(int $documentId, BrainSuggestionAction $action, ?string $target): bool
    {
        return BrainSuggestion::where('brain_document_id', $documentId)
            ->where('action', $action->value)
            ->where('target', $target)
            ->where('status', BrainSuggestionStatus::Rejected->value)
            ->where('created_at', '>=', now()->subDays(30))
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $frontmatterPatch
     */
    private function tryAutoApply(BrainSuggestionAction $action, ?array $frontmatterPatch, ?string $target): ?Model
    {
        return match ($action) {
            BrainSuggestionAction::CreateTask => $this->tryAutoCreateTask($frontmatterPatch),
            BrainSuggestionAction::CreateListItem => $this->tryAutoCreateListItem($target, $frontmatterPatch),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $frontmatterPatch
     */
    private function tryAutoCreateTask(?array $frontmatterPatch): ?Task
    {
        $title = trim((string) ($frontmatterPatch['title'] ?? ''));

        if ($title === '' || $this->fuzzyMatcher->bestMatch(Task::open()->get(), $title, fn (Task $task): string => $task->title) !== null) {
            return null;
        }

        $date = ! empty($frontmatterPatch['date']) ? (new QuickAddParser)->parse((string) $frontmatterPatch['date'])->date : null;
        $entity = ! empty($frontmatterPatch['entity']) ? $this->anchorResolver->resolveEntity((string) $frontmatterPatch['entity']) : null;
        $goal = ! empty($frontmatterPatch['goal']) ? $this->anchorResolver->resolveGoal((string) $frontmatterPatch['goal']) : null;

        return Task::create([
            'title' => $title,
            'scheduled_date' => $date,
            'entity_id' => $entity?->id,
            'goal_id' => $goal?->id,
            'status' => 'todo',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $frontmatterPatch
     */
    private function tryAutoCreateListItem(?string $target, ?array $frontmatterPatch): ?ChecklistItem
    {
        if (! $target) {
            return null;
        }

        $checklist = $this->fuzzyMatcher->bestMatch(Checklist::all(), $target, fn (Checklist $checklist): string => $checklist->name);

        if (! $checklist) {
            return null;
        }

        $content = trim((string) ($frontmatterPatch['content'] ?? ''));

        if ($content === '') {
            return null;
        }

        $openItems = $checklist->items()->whereNull('checked_at')->get();

        if ($this->fuzzyMatcher->bestMatch($openItems, $content, fn (ChecklistItem $item): string => $item->content) !== null) {
            return null;
        }

        return $checklist->items()->create([
            'content' => $content,
            'position' => $checklist->items()->count(),
        ]);
    }

    private function markProcessed(BrainDocument $document): void
    {
        $vaultPath = rtrim($this->settings->localPath(), '/');
        $absolutePath = "{$vaultPath}/{$document->path}";

        if (! File::exists($absolutePath)) {
            return;
        }

        $frontmatter = $document->frontmatter ?? [];
        $frontmatter['type'] = 'traite';

        $raw = File::get($absolutePath);
        $body = preg_match('/^---\r?\n.*?\r?\n---\r?\n?(.*)$/us', $raw, $matches) ? $matches[1] : $raw;

        File::put($absolutePath, "---\n".Yaml::dump($frontmatter)."---\n\n{$body}");
        $this->git->commit($vaultPath, $document->path, "tars: curator mark traité {$document->path}");

        $document->update(['frontmatter' => $frontmatter]);
    }

    private function curatorConfig(): ?AgentConfig
    {
        return AgentConfig::where('agent_name', AgentName::Curateur->value)
            ->where('enabled', true)
            ->first();
    }
}
