<?php

namespace App\Support\Search;

use App\Models\BrainDocument;
use App\Models\Checklist;
use App\Models\Decision;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\Milestone;
use App\Models\Note;
use App\Models\Task;
use App\Support\FuzzyMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Cherche la même chose dans tout ce que l'app garde, et rend des destinations
 * plutôt que des modèles : une recherche qui ne mène nulle part ne sert à rien.
 * Chaque source est bornée avant classement — à l'échelle d'une vie rangée,
 * neuf petites requêtes coûtent moins qu'un index à maintenir.
 */
class GlobalSearch
{
    private const PER_SOURCE = 6;

    /** En deçà, tout correspond : on ne cherche pas sur une seule lettre. */
    private const MIN_TERM_LENGTH = 2;

    public function __construct(private readonly FuzzyMatcher $matcher) {}

    /**
     * @return Collection<int, SearchHit>
     */
    public function for(string $term, int $limit = 8): Collection
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return collect();
        }

        $hits = [
            ...$this->goals($term),
            ...$this->tasks($term),
            ...$this->entities($term),
            ...$this->milestones($term),
            ...$this->decisions($term),
            ...$this->notes($term),
            ...$this->checklists($term),
            ...$this->brainDocuments($term),
            ...$this->inboxItems($term),
        ];

        return collect($hits)
            ->sortByDesc(fn (SearchHit $hit): float => $hit->score)
            ->take($limit)
            ->values();
    }

    /**
     * @return array<int, SearchHit>
     */
    private function goals(string $term): array
    {
        return Goal::query()
            ->where(function (Builder $query) use ($term): void {
                $this->matchAny($query, $term, ['title', 'description']);
            })
            ->with('entity')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (Goal $goal): SearchHit => $this->hit(
                'Objectif',
                $goal->title,
                $goal->entity?->name,
                route('goals.show', $goal),
                $term,
            ))
            ->all();
    }

    /**
     * Il n'existe pas d'écran par tâche : une tâche mène à la liste filtrée
     * sur le terme cherché, qui la contient forcément.
     *
     * @return array<int, SearchHit>
     */
    private function tasks(string $term): array
    {
        return Task::query()
            ->where(function (Builder $query) use ($term): void {
                $this->matchAny($query, $term, ['title', 'notes']);
            })
            ->with('goal')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (Task $task): SearchHit => $this->hit(
                'Tâche',
                $task->title,
                $task->goal?->title,
                route('tasks.index', ['search' => $term]),
                $term,
            ))
            ->all();
    }

    /**
     * @return array<int, SearchHit>
     */
    private function entities(string $term): array
    {
        return Entity::query()
            ->where(function (Builder $query) use ($term): void {
                $this->matchAny($query, $term, ['name', 'notes']);
            })
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (Entity $entity): SearchHit => $this->hit(
                'Entité',
                $entity->name,
                $entity->type->label(),
                route('entities.show', $entity),
                $term,
            ))
            ->all();
    }

    /**
     * @return array<int, SearchHit>
     */
    private function milestones(string $term): array
    {
        return Milestone::query()
            ->where('title', 'like', $this->wildcard($term))
            ->with('goal')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (Milestone $milestone): SearchHit => $this->hit(
                'Jalon',
                $milestone->title,
                $milestone->goal?->title,
                route('goals.show', $milestone->goal_id),
                $term,
            ))
            ->all();
    }

    /**
     * @return array<int, SearchHit>
     */
    private function decisions(string $term): array
    {
        return Decision::query()
            ->where(function (Builder $query) use ($term): void {
                $this->matchAny($query, $term, ['content', 'context']);
            })
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (Decision $decision): SearchHit => $this->hit(
                'Décision',
                Str::limit($decision->content, 80),
                $decision->decided_at->translatedFormat('j M Y'),
                route('decisions.show', $decision),
                $term,
            ))
            ->all();
    }

    /**
     * Une note vit sous un objectif ou une entité : sans parent, elle n'a pas
     * d'écran où atterrir et ne sort donc pas des résultats.
     *
     * @return array<int, SearchHit>
     */
    private function notes(string $term): array
    {
        return Note::query()
            ->where(function (Builder $query) use ($term): void {
                $this->matchAny($query, $term, ['title', 'content']);
            })
            ->with(['goal', 'entity'])
            ->limit(self::PER_SOURCE)
            ->get()
            ->filter(fn (Note $note): bool => $note->goal_id !== null || $note->entity_id !== null)
            ->map(fn (Note $note): SearchHit => $this->hit(
                'Note',
                $note->title ?: Str::limit($note->content, 80),
                $note->goal_id !== null ? $note->goal->title : $note->entity->name,
                $this->noteUrl($note),
                $term,
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<int, SearchHit>
     */
    private function checklists(string $term): array
    {
        return Checklist::query()
            ->where('name', 'like', $this->wildcard($term))
            ->withCount('items')
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (Checklist $checklist): SearchHit => $this->hit(
                'Liste',
                $checklist->name,
                $checklist->items_count.' '.Str::plural('élément', (int) $checklist->items_count),
                route('lists.index'),
                $term,
            ))
            ->all();
    }

    /**
     * @return array<int, SearchHit>
     */
    private function brainDocuments(string $term): array
    {
        return BrainDocument::query()
            ->search($term)
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (BrainDocument $document): SearchHit => $this->hit(
                'Cerveau',
                $document->title,
                $document->path,
                route('brain.index', ['path' => $document->path]),
                $term,
            ))
            ->all();
    }

    /**
     * @return array<int, SearchHit>
     */
    private function inboxItems(string $term): array
    {
        return InboxItem::query()
            ->whereNull('processed_at')
            ->where('content', 'like', $this->wildcard($term))
            ->limit(self::PER_SOURCE)
            ->get()
            ->map(fn (InboxItem $item): SearchHit => $this->hit(
                'Inbox',
                Str::limit($item->content, 80),
                'pas encore trié',
                route('inbox.index'),
                $term,
            ))
            ->all();
    }

    private function noteUrl(Note $note): string
    {
        return $note->goal_id !== null
            ? route('goals.show', $note->goal_id)
            : route('entities.show', $note->entity_id);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     */
    private function matchAny(Builder $query, string $term, array $columns): void
    {
        foreach ($columns as $column) {
            $query->orWhere($column, 'like', $this->wildcard($term));
        }
    }

    private function wildcard(string $term): string
    {
        return '%'.$term.'%';
    }

    private function hit(string $kind, string $label, ?string $hint, string $url, string $term): SearchHit
    {
        return new SearchHit($kind, $label, $hint, $url, $this->score($label, $term));
    }

    /**
     * C'est le titre qui porte le score. Une ligne trouvée par son seul corps —
     * le contenu d'une note, d'un document — garde un plancher, sinon elle
     * passerait derrière n'importe quelle correspondance de titre douteuse.
     */
    private function score(string $label, string $term): float
    {
        return max((float) $this->matcher->score(Str::lower($label), Str::lower($term)), 60.0);
    }
}
