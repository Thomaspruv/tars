<?php

namespace App\Support\Curator;

use App\Enums\BrainSuggestionStatus;
use App\Enums\GoalStatus;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Models\Checklist;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\McpCall;
use App\Models\Task;
use App\Support\Brain\BrainHealth;
use App\Support\Brain\VaultLayout;
use App\Support\FuzzyMatcher;
use Illuminate\Support\Collection;

class CuratorContextBuilder
{
    /**
     * One LLM call per batch: a migration of the whole vault is a long job, and
     * a batch Thomas can read is a batch he actually validates.
     */
    public const int BATCH_SIZE = 40;

    /**
     * Above this similarity, two living notes in the same folder are probably
     * the same subject written twice.
     */
    private const float DUPLICATE_THRESHOLD = 0.85;

    /**
     * Folders where a second note on the same subject is a merge signal.
     */
    private const array DUPLICATE_FOLDERS = ['Notes', 'Personnes', 'Organisations'];

    public function __construct(
        private readonly BrainHealth $health,
        private readonly VaultLayout $layout,
        private readonly FuzzyMatcher $fuzzyMatcher = new FuzzyMatcher,
    ) {}

    /**
     * Folders the curator is ever allowed to touch: the ontology's own *in
     * this vault*, and nothing else. Hermes's technical memory — whatever its
     * folders are actually called — and any stray path outside the documented
     * tree stay out of scope.
     *
     * @return list<string>
     */
    public function managedFolders(): array
    {
        return $this->layout->managedFolders();
    }

    /**
     * Le dossier où le type de ce document devrait vivre dans ce vault.
     */
    private function folderForDocument(BrainDocument $document): string
    {
        $type = $document->noteType();

        return $type !== null ? $this->layout->folderFor($type) : '?';
    }

    public function build(string $mission): string
    {
        $notes = $this->notesToProcess($mission);

        return implode("\n\n", array_filter([
            $this->buildReferential(),
            $this->buildLivingMap(),
            $this->buildNotesToProcess($notes),
            $this->buildRecentMcpCalls(),
            $this->buildOpenTasksAndLists(),
            $this->buildRecentRejections($notes),
        ]));
    }

    /**
     * @return Collection<int, BrainDocument>
     */
    public function notesToProcess(string $mission): Collection
    {
        if ($mission === 'todo') {
            return BrainDocument::where('path', 'like', 'TARS/%')
                ->get()
                ->filter(fn (BrainDocument $document): bool => ($document->frontmatter['type'] ?? null) === 'a-traiter'
                    && ($document->frontmatter['status'] ?? null) !== 'done')
                ->values();
        }

        return BrainDocument::where('path', 'not like', 'Profil/%')
            ->get()
            ->filter(fn (BrainDocument $document): bool => self::isManagedPath($document->path))
            ->filter(fn (BrainDocument $document): bool => str_starts_with($document->path, 'TARS/')
                || ($document->entities()->count() === 0 && $document->goals()->count() === 0))
            ->values();
    }

    public function isManagedPath(string $path): bool
    {
        return in_array(explode('/', $path)[0], $this->managedFolders(), true);
    }

    /**
     * The documents the conformance mission has to look at: non conformant
     * ones, conformant ones sitting in the wrong folder, and probable
     * duplicates — oldest first, so a migration converges.
     *
     * @return Collection<int, BrainDocument>
     */
    public function conformanceDocuments(): Collection
    {
        $nonConformant = BrainDocument::underContract()
            ->where('is_conformant', false)
            ->orderBy('path')
            ->get();

        // A document already waiting in a batch is not submitted again: a
        // second pending proposal on the same note cannot be applied after the
        // first one moved it.
        $alreadyProposed = BrainSuggestion::query()
            ->where('status', BrainSuggestionStatus::Pending)
            ->whereNotNull('batch_id')
            ->pluck('brain_document_id')
            ->all();

        return $nonConformant
            ->concat($this->health->misplaced())
            ->concat($this->probableDuplicates())
            ->unique('id')
            ->reject(fn (BrainDocument $document): bool => in_array($document->id, $alreadyProposed, true))
            ->values();
    }

    /**
     * The context of one conformance batch — the referential it may anchor
     * against, the documents themselves, the living map it could merge into,
     * and what Thomas already refused.
     *
     * @param  Collection<int, BrainDocument>  $batch
     */
    public function forConformance(Collection $batch): string
    {
        return implode("\n\n", array_filter([
            $this->buildReferential(),
            $this->buildLivingMap(),
            $this->buildConformanceDocuments($batch),
            $this->buildRecentRejections($batch),
        ]));
    }

    /**
     * @param  Collection<int, BrainDocument>  $batch
     */
    private function buildConformanceDocuments(Collection $batch): string
    {
        if ($batch->isEmpty()) {
            return "## Documents à mettre en conformité\nAucun document à traiter.";
        }

        $blocks = $batch->map(function (BrainDocument $document): string {
            $frontmatter = collect($document->frontmatter ?? [])
                ->map(fn (mixed $value, string $key): string => "{$key}: ".(is_array($value) ? json_encode($value) : (string) $value))
                ->implode("\n");

            $diagnosis = implode(' ', array_filter([
                $document->is_conformant ? 'Conforme.' : 'Non conforme : '.implode(' ', $document->schemaErrors()),
                $this->health->isMisplaced($document) ? "Mal placé : le type « {$document->note_type} » appartient au dossier {$this->folderForDocument($document)}/." : null,
            ]));

            return <<<MD
            ### {$document->path}
            Diagnostic : {$diagnosis}

            Frontmatter actuel :
            {$frontmatter}

            Contenu :
            {$document->content}
            MD;
        })->implode("\n\n");

        return "## Documents à mettre en conformité\n{$blocks}";
    }

    /**
     * Pairs of living notes whose titles are close enough to be the same
     * subject — only the newer of each pair is submitted, since it is the one
     * that would be merged away.
     *
     * @return Collection<int, BrainDocument>
     */
    private function probableDuplicates(): Collection
    {
        $candidates = BrainDocument::underContract()
            ->where(function ($query): void {
                foreach (self::DUPLICATE_FOLDERS as $folder) {
                    $query->orWhere('path', 'like', "{$folder}/%");
                }
            })
            ->orderBy('path')
            ->get();

        $duplicates = collect();
        $paired = [];

        foreach ($candidates as $document) {
            if (isset($paired[$document->id])) {
                continue;
            }

            $twin = $candidates->first(fn (BrainDocument $other): bool => $other->id !== $document->id
                && ! isset($paired[$other->id])
                && dirname($other->path) === dirname($document->path)
                && $this->fuzzyMatcher->score((string) $other->title, (string) $document->title) >= self::DUPLICATE_THRESHOLD * 100);

            if ($twin === null) {
                continue;
            }

            // Submit one of the pair only: two reciprocal merges in the same
            // batch would write each note into the other.
            $paired[$document->id] = true;
            $paired[$twin->id] = true;
            $duplicates->push($document);
        }

        return $duplicates->values();
    }

    private function buildReferential(): string
    {
        $entities = Entity::where('status', 'active')
            ->get()
            ->map(fn (Entity $entity): string => "- {$entity->name} ({$entity->type->value})")
            ->implode("\n");

        $goals = Goal::where('status', GoalStatus::Active)
            ->get()
            ->map(fn (Goal $goal): string => "- {$goal->title}")
            ->implode("\n");

        return <<<MD
        ## Référentiel d'ancrage
        Entités :
        {$entities}

        Objectifs actifs :
        {$goals}
        MD;
    }

    private function buildLivingMap(): string
    {
        $notes = BrainDocument::where('path', 'like', 'Notes/%')
            ->with(['entities', 'goals'])
            ->get();

        if ($notes->isEmpty()) {
            return "## Carte du vivant (Notes/)\nAucune note vivante pour l'instant.";
        }

        $lines = $notes->map(function (BrainDocument $document): string {
            $anchors = $document->entities->pluck('name')->merge($document->goals->pluck('title'))->implode(', ');

            return "- {$document->path} — {$document->title}".($anchors ? " [{$anchors}]" : ' [sans ancre]');
        })->implode("\n");

        return "## Carte du vivant (Notes/)\n{$lines}";
    }

    /**
     * @param  Collection<int, BrainDocument>  $notes
     */
    private function buildNotesToProcess(Collection $notes): string
    {
        if ($notes->isEmpty()) {
            return "## Notes à traiter\nAucune note à traiter.";
        }

        $blocks = $notes->map(function (BrainDocument $document): string {
            $frontmatter = collect($document->frontmatter ?? [])
                ->map(fn ($value, $key): string => "{$key}: ".(is_array($value) ? json_encode($value) : $value))
                ->implode("\n");

            return <<<MD
            ### {$document->path}
            Frontmatter :
            {$frontmatter}

            Contenu :
            {$document->content}
            MD;
        })->implode("\n\n");

        return "## Notes à traiter\n{$blocks}";
    }

    private function buildRecentMcpCalls(): string
    {
        $calls = McpCall::where('created_at', '>=', now()->subHours(48))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        if ($calls->isEmpty()) {
            return "## Appels MCP (48 dernières heures)\nAucun appel récent.";
        }

        $lines = $calls->map(fn (McpCall $call): string => "- {$call->tool}(".json_encode($call->parameters).')')->implode("\n");

        return "## Appels MCP (48 dernières heures)\n{$lines}";
    }

    private function buildOpenTasksAndLists(): string
    {
        $tasks = Task::open()->pluck('title')->implode(', ');
        $lists = Checklist::pluck('name')->implode(', ');

        return <<<MD
        ## Tâches ouvertes et listes existantes (pour éviter les doublons)
        Tâches ouvertes : {$tasks}.
        Listes existantes : {$lists}.
        MD;
    }

    /**
     * @param  Collection<int, BrainDocument>  $notes
     */
    private function buildRecentRejections(Collection $notes): string
    {
        if ($notes->isEmpty()) {
            return '';
        }

        $rejections = BrainSuggestion::where('status', BrainSuggestionStatus::Rejected)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('brain_document_id', $notes->pluck('id'))
            ->with('brainDocument')
            ->get();

        if ($rejections->isEmpty()) {
            return "## Refus récents (30 derniers jours)\nAucun refus récent — rien à éviter de re-proposer.";
        }

        $lines = $rejections->map(fn (BrainSuggestion $rejection): string => "- {$rejection->brainDocument->path} : {$rejection->action->value}".($rejection->target ? " → {$rejection->target}" : '').' — ne jamais re-proposer.')->implode("\n");

        return "## Refus récents (30 derniers jours)\n{$lines}";
    }
}
