<?php

namespace App\Support\Curator;

use App\Enums\BrainSuggestionAction;
use App\Enums\BrainSuggestionStatus;
use App\Models\BrainSuggestion;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Goal;
use App\Models\LifeArea;
use App\Models\Task;
use App\Support\Brain\AnchorResolver;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\Brain\VaultIndexer;
use App\Support\FuzzyMatcher;
use App\Support\QuickAdd\QuickAddParser;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class SuggestionApplier
{
    public function __construct(
        private readonly BrainSettings $settings,
        private readonly GitRepository $git,
        private readonly VaultIndexer $indexer,
        private readonly AnchorResolver $anchorResolver,
        private readonly FuzzyMatcher $fuzzyMatcher,
    ) {}

    /**
     * @throws RuntimeException when the suggestion cannot be applied — the
     *                          caller is expected to show the message and leave the suggestion pending.
     */
    public function apply(BrainSuggestion $suggestion): void
    {
        match ($suggestion->action) {
            BrainSuggestionAction::Anchor, BrainSuggestionAction::Complete => $this->applyFrontmatterPatch($suggestion),
            BrainSuggestionAction::Move => $this->applyMove($suggestion),
            BrainSuggestionAction::Merge => $this->applyMerge($suggestion),
            BrainSuggestionAction::Archive => $this->applyArchive($suggestion),
            BrainSuggestionAction::CreateTask => $this->applyCreateTask($suggestion),
            BrainSuggestionAction::CreateListItem => $this->applyCreateListItem($suggestion),
            BrainSuggestionAction::CreateGoal => $this->applyCreateGoal($suggestion),
        };

        $suggestion->update(['status' => BrainSuggestionStatus::Accepted]);
    }

    private function applyFrontmatterPatch(BrainSuggestion $suggestion): void
    {
        $document = $suggestion->brainDocument;
        $vaultPath = rtrim($this->settings->localPath(), '/');
        $absolutePath = "{$vaultPath}/{$document->path}";

        $frontmatter = array_merge($document->frontmatter ?? [], $suggestion->frontmatter_patch ?? []);
        [, $body] = $this->splitFrontmatter(File::get($absolutePath));

        File::put($absolutePath, $this->render($frontmatter, $body));
        $this->git->commit($vaultPath, $document->path, "tars: curator {$suggestion->action->value} {$document->path}");
        $this->indexer->indexFile($absolutePath);
    }

    private function applyMove(BrainSuggestion $suggestion): void
    {
        $document = $suggestion->brainDocument;
        $target = $suggestion->target;

        if (! $target) {
            throw new RuntimeException('Aucune destination fournie pour ce déplacement.');
        }

        $vaultPath = rtrim($this->settings->localPath(), '/');
        $sourcePath = "{$vaultPath}/{$document->path}";
        $destPath = "{$vaultPath}/{$target}";

        [$frontmatter, $body] = $this->splitFrontmatter(File::get($sourcePath));
        $frontmatter = array_merge($frontmatter, $suggestion->frontmatter_patch ?? []);

        File::ensureDirectoryExists(dirname($destPath));
        File::put($destPath, $this->render($frontmatter, $body));
        File::delete($sourcePath);

        $this->git->commitAll($vaultPath, "tars: curator move {$document->path} → {$target}");

        $document->delete();
        $this->indexer->indexFile($destPath);
    }

    private function applyMerge(BrainSuggestion $suggestion): void
    {
        $document = $suggestion->brainDocument;
        $target = $suggestion->target;

        if (! $target || ! $suggestion->merged_content) {
            throw new RuntimeException('Fusion incomplète : destination ou contenu fusionné manquant.');
        }

        $vaultPath = rtrim($this->settings->localPath(), '/');
        $sourcePath = "{$vaultPath}/{$document->path}";
        $targetPath = "{$vaultPath}/{$target}";
        $archivePath = "{$vaultPath}/Archives/".basename($document->path);

        File::ensureDirectoryExists(dirname($targetPath));
        File::put($targetPath, $suggestion->merged_content);

        $this->archiveSourceFile($sourcePath, $archivePath);

        $this->git->commitAll($vaultPath, "tars: curator merge {$document->path} → {$target}");

        $document->delete();
        $this->indexer->indexFile($targetPath);
        $this->indexer->indexFile($archivePath);
    }

    private function applyArchive(BrainSuggestion $suggestion): void
    {
        $document = $suggestion->brainDocument;
        $vaultPath = rtrim($this->settings->localPath(), '/');
        $sourcePath = "{$vaultPath}/{$document->path}";
        $archivePath = "{$vaultPath}/Archives/".basename($document->path);

        $this->archiveSourceFile($sourcePath, $archivePath, $suggestion->frontmatter_patch ?? []);

        $this->git->commitAll($vaultPath, "tars: curator archive {$document->path}");

        $document->delete();
        $this->indexer->indexFile($archivePath);
    }

    /**
     * @param  array<string, mixed>  $extraFrontmatter
     */
    private function archiveSourceFile(string $sourcePath, string $archivePath, array $extraFrontmatter = []): void
    {
        [$frontmatter, $body] = $this->splitFrontmatter(File::get($sourcePath));
        $frontmatter = array_merge($frontmatter, $extraFrontmatter, ['archived' => now()->toDateString()]);

        File::ensureDirectoryExists(dirname($archivePath));
        File::put($archivePath, $this->render($frontmatter, $body));
        File::delete($sourcePath);
    }

    private function applyCreateTask(BrainSuggestion $suggestion): void
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
    }

    private function applyCreateListItem(BrainSuggestion $suggestion): void
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
    }

    private function applyCreateGoal(BrainSuggestion $suggestion): void
    {
        $patch = $suggestion->frontmatter_patch ?? [];
        $title = trim((string) ($patch['title'] ?? ''));

        if ($title === '') {
            throw new RuntimeException("Titre d'objectif manquant.");
        }

        $lifeArea = ! empty($patch['life_area'])
            ? $this->fuzzyMatcher->bestMatch(LifeArea::all(), (string) $patch['life_area'], fn (LifeArea $lifeArea): string => $lifeArea->name)
            : null;

        if (! $lifeArea) {
            throw new RuntimeException('Domaine de vie introuvable ou non précisé pour cet objectif.');
        }

        $entity = ! empty($patch['entity']) ? $this->anchorResolver->resolveEntity((string) $patch['entity']) : null;

        $goal = Goal::create([
            'life_area_id' => $lifeArea->id,
            'entity_id' => $entity?->id,
            'title' => $title,
        ]);

        $suggestion->update(['created_type' => Goal::class, 'created_id' => $goal->id]);
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

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    private function render(array $frontmatter, string $body): string
    {
        return "---\n".Yaml::dump($frontmatter)."---\n\n{$body}";
    }
}
