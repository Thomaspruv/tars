<?php

namespace App\Support\Curator;

use App\Enums\BrainSuggestionStatus;
use App\Enums\GoalStatus;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Models\Checklist;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\LifeArea;
use App\Models\McpCall;
use App\Models\Task;
use Illuminate\Support\Collection;

class CuratorContextBuilder
{
    /**
     * Folders the curator is ever allowed to touch — anything else (Hermes's
     * own memory territory, or paths outside the vault) is out of scope.
     */
    public const array MANAGED_FOLDERS = ['Profil', 'Réunions', 'Notes', 'TARS', 'Archives'];

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
                ->filter(fn (BrainDocument $document): bool => ($document->frontmatter['type'] ?? null) === 'a-traiter')
                ->values();
        }

        return BrainDocument::where('path', 'not like', 'Profil/%')
            ->get()
            ->filter(fn (BrainDocument $document): bool => self::isManagedPath($document->path))
            ->filter(fn (BrainDocument $document): bool => str_starts_with($document->path, 'TARS/')
                || ($document->entities()->count() === 0 && $document->goals()->count() === 0))
            ->values();
    }

    /**
     * Only the 5 documented vault folders are ever the curator's business —
     * anything else (Hermes's own memory territory, whatever it's actually
     * named, or a stray file outside the documented structure) is excluded.
     */
    public static function isManagedPath(string $path): bool
    {
        $topFolder = explode('/', $path)[0];

        return in_array($topFolder, self::MANAGED_FOLDERS, true);
    }

    private function buildReferential(): string
    {
        $lifeAreas = LifeArea::pluck('name')->implode(', ');

        $entities = Entity::where('status', 'active')
            ->with('lifeArea')
            ->get()
            ->map(fn (Entity $entity): string => "- {$entity->name} ({$entity->type->value}, {$entity->lifeArea->name})")
            ->implode("\n");

        $goals = Goal::where('status', GoalStatus::Active)
            ->with('lifeArea')
            ->get()
            ->map(fn (Goal $goal): string => "- {$goal->title} ({$goal->lifeArea->name})")
            ->implode("\n");

        return <<<MD
        ## Référentiel d'ancrage
        Domaines de vie : {$lifeAreas}.

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
