<?php

namespace App\Mcp\Support;

use App\Models\BrainDocument;
use App\Models\Checklist;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\Task;
use App\Support\FuzzyMatcher;
use Illuminate\Support\Collection;

class NameResolver
{
    public function __construct(private readonly FuzzyMatcher $matcher = new FuzzyMatcher) {}

    /**
     * @return Collection<int, Entity>
     */
    public function entities(string $needle): Collection
    {
        return $this->matcher->rankedMatches(Entity::all(), $needle, fn (Entity $entity): string => $entity->name);
    }

    /**
     * @return Collection<int, Goal>
     */
    public function goals(string $needle): Collection
    {
        return $this->matcher->rankedMatches(Goal::all(), $needle, fn (Goal $goal): string => $goal->title);
    }

    /**
     * @return Collection<int, Checklist>
     */
    public function checklists(string $needle): Collection
    {
        return $this->matcher->rankedMatches(Checklist::all(), $needle, fn (Checklist $checklist): string => $checklist->name);
    }

    /**
     * @return Collection<int, Task>
     */
    public function openTasks(string $needle): Collection
    {
        return $this->matcher->rankedMatches(Task::open()->get(), $needle, fn (Task $task): string => $task->title);
    }

    /**
     * Unlike openTasks(), matches against every task regardless of status — used for
     * destructive operations where the caller may want to delete a task that's already done.
     *
     * @return Collection<int, Task>
     */
    public function tasks(string $needle): Collection
    {
        return $this->matcher->rankedMatches(Task::all(), $needle, fn (Task $task): string => $task->title);
    }

    /**
     * @return Collection<int, BrainDocument>
     */
    public function notes(string $needle): Collection
    {
        return $this->matcher->rankedMatches(BrainDocument::all(), $needle, fn (BrainDocument $document): string => (string) $document->title);
    }

    /**
     * Reduce a ranked candidate list to a single match, or throw if more than one is still tied above threshold.
     * Returns null (not an exception) when there are no candidates at all — callers decide how to phrase "not found".
     *
     * @template TModel
     *
     * @param  Collection<int, TModel>  $candidates
     * @param  \Closure(TModel): string  $label
     * @return TModel|null
     *
     * @throws AmbiguousToolCall
     */
    public function disambiguate(Collection $candidates, \Closure $label, string $ambiguousMessage): mixed
    {
        if ($candidates->count() > 1) {
            $names = $candidates->map($label)->implode(', ');

            throw new AmbiguousToolCall("{$ambiguousMessage} : {$names}. Précise laquelle.");
        }

        return $candidates->first();
    }
}
