<?php

namespace App\Support\Curator;

use App\Enums\BrainSuggestionAction;
use App\Enums\BrainSuggestionStatus;
use App\Models\BrainSuggestion;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Goal;
use App\Models\Task;
use App\Support\Brain\AnchorResolver;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\Schema\FrontmatterBuilder;
use App\Support\Brain\VaultOperation;
use App\Support\Brain\VaultWriter;
use App\Support\FuzzyMatcher;
use App\Support\QuickAdd\QuickAddParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class SuggestionApplier
{
    public function __construct(
        private readonly BrainSettings $settings,
        private readonly VaultWriter $writer,
        private readonly AnchorResolver $anchorResolver,
        private readonly FuzzyMatcher $fuzzyMatcher,
        private readonly FrontmatterBuilder $frontmatter,
    ) {}

    /**
     * @throws RuntimeException when the suggestion cannot be applied — the
     *                          caller is expected to show the message and leave the suggestion pending.
     */
    public function apply(BrainSuggestion $suggestion): void
    {
        $operations = $this->operationsFor($suggestion);

        if ($operations !== []) {
            $this->commit($operations, $this->commitMessageFor($suggestion));
        }

        $suggestion->update(['status' => BrainSuggestionStatus::Accepted]);
    }

    /**
     * Accepts a whole conformance batch in one commit — « accepter tout le
     * lot » has to read as one movement in the vault's history, not twenty.
     *
     * Two suggestions landing on the same file would silently overwrite each
     * other in a single commit, so such a batch falls back to one commit per
     * suggestion.
     *
     * @param  Collection<int, BrainSuggestion>  $suggestions
     *
     * @throws RuntimeException
     */
    public function applyBatch(Collection $suggestions, string $commitMessage): void
    {
        $operations = [];

        foreach ($suggestions as $suggestion) {
            foreach ($this->operationsFor($suggestion) as $operation) {
                $operations[] = $operation;
            }
        }

        // Both ends of a move count: a batch that archives A and also writes
        // to A would recreate it, leaving the note at two paths at once.
        $paths = [];

        foreach ($operations as $operation) {
            $paths[] = $operation->relativePath;

            if ($operation->isMove()) {
                $paths[] = (string) $operation->movedFrom;
            }
        }

        if (count($paths) !== count(array_unique($paths))) {
            foreach ($suggestions as $suggestion) {
                $this->apply($suggestion);
            }

            return;
        }

        if ($operations !== []) {
            $this->commit($operations, $commitMessage);
        }

        BrainSuggestion::whereIn('id', $suggestions->pluck('id'))->update(['status' => BrainSuggestionStatus::Accepted->value]);
    }

    /**
     * What a suggestion changes in the vault — empty for the actions that
     * create a task, a list item or a goal instead (those apply right here).
     *
     * @return list<VaultOperation>
     */
    private function operationsFor(BrainSuggestion $suggestion): array
    {
        return match ($suggestion->action) {
            BrainSuggestionAction::Anchor, BrainSuggestionAction::Complete => [$this->frontmatterPatchOperation($suggestion)],
            BrainSuggestionAction::Move => [$this->moveOperation($suggestion)],
            BrainSuggestionAction::Merge => $this->mergeOperations($suggestion),
            BrainSuggestionAction::Archive => [$this->archiveSuggestionOperation($suggestion)],
            BrainSuggestionAction::CreateTask => $this->applyCreateTask($suggestion),
            BrainSuggestionAction::CreateListItem => $this->applyCreateListItem($suggestion),
            BrainSuggestionAction::CreateGoal => $this->applyCreateGoal($suggestion),
        };
    }

    private function commitMessageFor(BrainSuggestion $suggestion): string
    {
        $path = $suggestion->brainDocument->path;

        return match ($suggestion->action) {
            BrainSuggestionAction::Move, BrainSuggestionAction::Merge => "curator {$suggestion->action->value} {$path} → {$suggestion->target}",
            default => "curator {$suggestion->action->value} {$path}",
        };
    }

    /**
     * Anchoring and completing both come down to rewriting the frontmatter —
     * and the curator always leaves it conformant to the v3 contract, which is
     * how the existing vault migrates note by note.
     */
    private function frontmatterPatchOperation(BrainSuggestion $suggestion): VaultOperation
    {
        $document = $suggestion->brainDocument;

        [, $body] = $this->splitFrontmatter($this->read($document->path));

        return VaultOperation::write($document->path, $this->contractual($suggestion, $document->path, $body), $body);
    }

    private function moveOperation(BrainSuggestion $suggestion): VaultOperation
    {
        $document = $suggestion->brainDocument;
        $target = $suggestion->target;

        if (! $target) {
            throw new RuntimeException('Aucune destination fournie pour ce déplacement.');
        }

        [, $body] = $this->splitFrontmatter($this->read($document->path));

        return VaultOperation::move($document->path, $target, $this->contractual($suggestion, $target, $body), $body);
    }

    /**
     * @return list<VaultOperation>
     */
    private function mergeOperations(BrainSuggestion $suggestion): array
    {
        $document = $suggestion->brainDocument;
        $target = $suggestion->target;

        if (! $target || ! $suggestion->merged_content) {
            throw new RuntimeException('Fusion incomplète : destination ou contenu fusionné manquant.');
        }

        $archivePath = 'Archives/'.basename($document->path);

        // The merged content may or may not carry its own frontmatter: what
        // counts is its body — the frontmatter stays TARS's business.
        [$mergedFrontmatter, $mergedBody] = $this->splitFrontmatter($suggestion->merged_content);
        [$targetFrontmatter] = $this->splitFrontmatter($this->readIfExists($target));

        return [
            VaultOperation::write($target, $this->frontmatter->upgrade(
                frontmatter: [...$targetFrontmatter, ...$mergedFrontmatter],
                relativePath: $target,
                fallbackTitle: $this->fallbackTitle($target, $mergedBody),
                source: 'agent-curateur',
            ), $mergedBody),
            $this->archiveOperation($document->path, $archivePath),
        ];
    }

    private function archiveSuggestionOperation(BrainSuggestion $suggestion): VaultOperation
    {
        $document = $suggestion->brainDocument;

        return $this->archiveOperation($document->path, 'Archives/'.basename($document->path), $suggestion->frontmatter_patch ?? []);
    }

    /**
     * Nothing is ever deleted: the source file moves under Archives/ with the
     * date it was archived.
     *
     * @param  array<string, mixed>  $extraFrontmatter
     */
    private function archiveOperation(string $sourcePath, string $archivePath, array $extraFrontmatter = []): VaultOperation
    {
        [$frontmatter, $body] = $this->splitFrontmatter($this->read($sourcePath));

        $frontmatter = $this->frontmatter->upgrade(
            frontmatter: $frontmatter,
            relativePath: $sourcePath,
            fallbackTitle: $this->fallbackTitle($sourcePath, $body),
            source: 'agent-curateur',
            patch: [...$extraFrontmatter, 'archived' => now()->toDateString()],
        );

        return VaultOperation::move($sourcePath, $archivePath, $frontmatter, $body);
    }

    /**
     * @param  list<VaultOperation>  $operations
     *
     * @throws RuntimeException when the vault refuses the write — the caller
     *                          shows the message and the suggestion stays pending.
     */
    private function commit(array $operations, string $commitMessage): void
    {
        $result = $this->writer->apply($operations, $commitMessage);

        if (! $result->isWritten()) {
            throw new RuntimeException($result->message());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function contractual(BrainSuggestion $suggestion, string $relativePath, string $body): array
    {
        return $this->frontmatter->upgrade(
            frontmatter: $suggestion->brainDocument->frontmatter ?? [],
            relativePath: $relativePath,
            fallbackTitle: (string) ($suggestion->brainDocument->title ?: $this->fallbackTitle($relativePath, $body)),
            source: 'agent-curateur',
            patch: $suggestion->frontmatter_patch ?? [],
        );
    }

    private function fallbackTitle(string $relativePath, string $body): string
    {
        if (preg_match('/^#\s+(.+)$/mu', $body, $matches)) {
            return trim($matches[1]);
        }

        return basename($relativePath, '.md');
    }

    /**
     * @throws RuntimeException when the note has left the vault since the
     *                          suggestion was made — there is nothing left to act on.
     */
    private function read(string $relativePath): string
    {
        $absolutePath = rtrim($this->settings->localPath(), '/')."/{$relativePath}";

        if (! File::exists($absolutePath)) {
            throw new RuntimeException("La note « {$relativePath} » n'est plus dans le vault.");
        }

        return File::get($absolutePath);
    }

    private function readIfExists(string $relativePath): string
    {
        $absolutePath = rtrim($this->settings->localPath(), '/')."/{$relativePath}";

        return File::exists($absolutePath) ? File::get($absolutePath) : '';
    }

    /**
     * @return list<VaultOperation>
     */
    private function applyCreateTask(BrainSuggestion $suggestion): array
    {
        $patch = $suggestion->frontmatter_patch ?? [];
        $title = trim((string) ($patch['title'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('Titre de tâche manquant.');
        }

        $date = ! empty($patch['date']) ? (new QuickAddParser)->parse((string) $patch['date'])->date : null;
        $entity = ! empty($patch['entity']) ? $this->anchorResolver->resolveEntity((string) $patch['entity']) : null;
        $goal = ! empty($patch['goal']) ? $this->anchorResolver->resolveGoal((string) $patch['goal']) : null;

        $task = Task::create([
            'title' => $title,
            'scheduled_date' => $date,
            'entity_id' => $entity?->id,
            'goal_id' => $goal?->id,
            'status' => 'todo',
        ]);

        $suggestion->update(['created_type' => Task::class, 'created_id' => $task->id]);

        return [];
    }

    /**
     * @return list<VaultOperation>
     */
    private function applyCreateListItem(BrainSuggestion $suggestion): array
    {
        $target = $suggestion->target;
        $content = trim((string) (($suggestion->frontmatter_patch ?? [])['content'] ?? ''));

        if (! $target || $content === '') {
            throw new RuntimeException('Liste ou contenu manquant.');
        }

        $checklist = $this->fuzzyMatcher->bestMatch(Checklist::all(), $target, fn (Checklist $checklist): string => $checklist->name);

        if (! $checklist) {
            throw new RuntimeException("Liste « {$target} » introuvable.");
        }

        $item = $checklist->items()->create([
            'content' => $content,
            'position' => $checklist->items()->count(),
        ]);

        $suggestion->update(['created_type' => ChecklistItem::class, 'created_id' => $item->id]);

        return [];
    }

    /**
     * @return list<VaultOperation>
     */
    private function applyCreateGoal(BrainSuggestion $suggestion): array
    {
        $patch = $suggestion->frontmatter_patch ?? [];
        $title = trim((string) ($patch['title'] ?? ''));

        if ($title === '') {
            throw new RuntimeException("Titre d'objectif manquant.");
        }

        $entity = ! empty($patch['entity']) ? $this->anchorResolver->resolveEntity((string) $patch['entity']) : null;

        $goal = Goal::create([
            'entity_id' => $entity?->id,
            'title' => $title,
        ]);

        $suggestion->update(['created_type' => Goal::class, 'created_id' => $goal->id]);

        return [];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function splitFrontmatter(string $raw): array
    {
        if (! preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/us', $raw, $matches)) {
            return [[], $raw];
        }

        $parsed = Yaml::parse($matches[1]);

        return [is_array($parsed) ? $parsed : [], $matches[2]];
    }
}
