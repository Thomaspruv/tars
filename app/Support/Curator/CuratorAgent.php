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
use App\Models\Goal;
use App\Models\Task;
use App\Support\Agents\AgentRunner;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\FuzzyMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class CuratorAgent
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly CuratorContextBuilder $contextBuilder,
        private readonly SuggestionApplier $applier,
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
            $this->processItem($item, $notesByPath, $run);
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
    private function processItem(array $item, Collection $notesByPath, AgentRun $run): void
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

        $suggestion = BrainSuggestion::create([...$data, 'status' => BrainSuggestionStatus::Pending]);

        // Only genuine doubt should ever land in front of Thomas: below "high"
        // confidence, or a plausible conflict with something that already
        // exists. Everything else — including vault housekeeping and goal
        // creation — auto-applies via the same SuggestionApplier the
        // Rangement tab uses for a manual "Accepter".
        if ($confidence === SuggestionConfidence::High && $this->hasNoConflict($action, $frontmatterPatch ?? [], $target)) {
            try {
                $this->applier->apply($suggestion);
                $suggestion->update(['status' => BrainSuggestionStatus::AutoApplied]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $frontmatterPatch
     */
    private function hasNoConflict(BrainSuggestionAction $action, array $frontmatterPatch, ?string $target): bool
    {
        return match ($action) {
            BrainSuggestionAction::CreateTask => $this->fuzzyMatcher->bestMatch(
                Task::open()->get(),
                trim((string) ($frontmatterPatch['title'] ?? '')),
                fn (Task $task): string => $task->title
            ) === null,
            BrainSuggestionAction::CreateListItem => $this->hasNoDuplicateListItem($target, $frontmatterPatch),
            BrainSuggestionAction::CreateGoal => $this->fuzzyMatcher->bestMatch(
                Goal::all(),
                trim((string) ($frontmatterPatch['title'] ?? '')),
                fn (Goal $goal): string => $goal->title
            ) === null,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $frontmatterPatch
     */
    private function hasNoDuplicateListItem(?string $target, array $frontmatterPatch): bool
    {
        if (! $target) {
            return true;
        }

        $checklist = $this->fuzzyMatcher->bestMatch(Checklist::all(), $target, fn (Checklist $checklist): string => $checklist->name);

        if (! $checklist) {
            return true;
        }

        $openItems = $checklist->items()->whereNull('checked_at')->get();
        $content = trim((string) ($frontmatterPatch['content'] ?? ''));

        return $this->fuzzyMatcher->bestMatch($openItems, $content, fn (ChecklistItem $item): string => $item->content) === null;
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
