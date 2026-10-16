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
use App\Support\Brain\BrainHealth;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\Schema\FrontmatterBuilder;
use App\Support\Brain\VaultParser;
use App\Support\Brain\VaultWriter;
use App\Support\FuzzyMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CuratorAgent
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly CuratorContextBuilder $contextBuilder,
        private readonly SuggestionApplier $applier,
        private readonly FuzzyMatcher $fuzzyMatcher,
        private readonly VaultWriter $writer,
        private readonly FrontmatterBuilder $frontmatter,
        private readonly BrainSettings $settings,
        private readonly VaultParser $parser,
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
     * Mission C — conformité : ramène l'existant au contrat, par lots que
     * Thomas valide. Un lot = un appel LLM = un AgentRun, tous loggés.
     *
     * @return Collection<int, AgentRun>
     */
    public function processConformance(AgentRunTrigger $trigger, ?int $limit = null): Collection
    {
        $config = $this->curatorConfig();

        if (! $config) {
            return new Collection;
        }

        $systemPrompt = str_replace(
            '{{VAULT_README}}',
            File::get(resource_path('vault/README.md')),
            File::get(resource_path('prompts/curator-conformance.md')),
        );

        $documents = $this->contextBuilder->conformanceDocuments();

        if ($limit !== null) {
            $documents = $documents->take($limit);
        }

        $runs = new Collection;

        foreach ($documents->chunk(CuratorContextBuilder::BATCH_SIZE) as $batch) {
            $context = $this->contextBuilder->forConformance($batch);
            $run = $this->runner->run($config, $systemPrompt, [['role' => 'user', 'content' => $context]], $trigger);
            $runs->push($run);

            if ($run->status !== AgentRunStatus::Success) {
                continue;
            }

            $items = $this->decodeAndValidate((string) $run->output);

            if ($items === null) {
                $run->update(['status' => AgentRunStatus::Failed, 'error' => 'Réponse du curateur invalide : JSON attendu, schéma non respecté.']);

                continue;
            }

            $batchId = (string) Str::uuid();
            $notesByPath = $batch->keyBy('path');

            foreach ($items as $item) {
                if (! in_array($item['action'], [...BrainHealth::conformanceActions(), 'none'], true)) {
                    report(new \RuntimeException("Suggestion de conformité ignorée : action « {$item['action']} » hors mission."));

                    continue;
                }

                $this->processItem($item, $notesByPath, $run, batchId: $batchId, autoApply: false);
            }
        }

        return $runs;
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
    private function processItem(array $item, Collection $notesByPath, AgentRun $run, ?string $batchId = null, bool $autoApply = true): void
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

        $target = is_string($item['target'] ?? null) ? $item['target'] : null;

        if ($this->isOutOfScope($document->path, $action, $target)) {
            report(new \RuntimeException("Suggestion du curateur ignorée : chemin hors périmètre « {$document->path} »".($target !== null ? " → « {$target} »" : '')." ({$action->value})."));

            return;
        }

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
            'batch_id' => $batchId,
        ];

        $suggestion = BrainSuggestion::create([...$data, 'status' => BrainSuggestionStatus::Pending]);

        // Only genuine doubt should ever land in front of Thomas: below "high"
        // confidence, or a plausible conflict with something that already
        // exists. Everything else — including vault housekeeping and goal
        // creation — auto-applies via the same SuggestionApplier the
        // Rangement tab uses for a manual "Accepter".
        if ($autoApply && $confidence === SuggestionConfidence::High && $this->hasNoConflict($action, $frontmatterPatch ?? [], $target)) {
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

    /**
     * A *source* may sit anywhere but Hermes's own memory — that is the whole
     * point of the migration: the legacy folders are outside the ontology, and
     * a note has to be allowed to leave them. A *destination*, on the other
     * hand, is always inside the ontology, which also rules out a hallucinated
     * path escaping the vault.
     */
    private function isOutOfScope(string $path, BrainSuggestionAction $action, ?string $target): bool
    {
        if (str_starts_with($path, '.hermes/')) {
            return true;
        }

        $topFolder = explode('/', $path)[0];

        if ($topFolder === 'Profil' && ! in_array($action, [BrainSuggestionAction::Anchor, BrainSuggestionAction::Complete], true)) {
            return true;
        }

        $movesFile = in_array($action, [BrainSuggestionAction::Move, BrainSuggestionAction::Merge], true);

        return $movesFile && ($target === null || ! CuratorContextBuilder::isManagedPath($target));
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
     * A sas note the curator has looked at is `status: done` — the contract's
     * own vocabulary, so the note stays conformant and stops coming back in
     * the hourly todo round.
     */
    private function markProcessed(BrainDocument $document): void
    {
        $absolutePath = rtrim($this->settings->localPath(), '/')."/{$document->path}";

        // The same run may just have archived or moved this note: writing it
        // back at its old path would resurrect it in the sas, for ever.
        if (! File::exists($absolutePath)) {
            return;
        }

        // Read from disk, never from the index: brain_documents is only as
        // fresh as the last indexation, and Thomas may have edited the note in
        // Obsidian since.
        $parsed = $this->parser->parse(File::get($absolutePath), basename($document->path, '.md'));

        $frontmatter = $this->frontmatter->upgrade(
            frontmatter: $parsed->frontmatter,
            relativePath: $document->path,
            fallbackTitle: $parsed->title,
            source: 'agent-curateur',
            patch: ['status' => 'done'],
        );

        $result = $this->writer->write($document->path, $frontmatter, $parsed->content, "curator traité {$document->path}");

        // A note left unmarked comes back at the next hourly round and is
        // re-analysed from scratch — worth knowing about.
        if (! $result->isWritten()) {
            report(new \RuntimeException("Note du sas non marquée traitée « {$document->path} » : ".$result->message()));
        }
    }

    private function curatorConfig(): ?AgentConfig
    {
        return AgentConfig::where('agent_name', AgentName::Curateur->value)
            ->where('enabled', true)
            ->first();
    }
}
