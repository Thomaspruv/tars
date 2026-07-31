<?php

namespace App\Support\Triage;

use App\Enums\GoalStatus;
use App\Enums\InboxSuggestionStatus;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Task;
use Illuminate\Support\Collection;

class TriageContextBuilder
{
    public function build(): string
    {
        $items = $this->pendingItems();

        return implode("\n\n", array_filter([
            $this->buildReferential(),
            $this->buildPendingItems($items),
            $this->buildOpenTasks(),
            $this->buildRecentRejections($items),
        ]));
    }

    /**
     * @return Collection<int, InboxItem>
     */
    public function pendingItems(): Collection
    {
        return InboxItem::whereNull('processed_at')->get();
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

    /**
     * @param  Collection<int, InboxItem>  $items
     */
    private function buildPendingItems(Collection $items): string
    {
        if ($items->isEmpty()) {
            return "## Items en attente\nAucun item en attente.";
        }

        $lines = $items->map(fn (InboxItem $item): string => "### #{$item->id}\n{$item->content}")->implode("\n\n");

        return "## Items en attente\n{$lines}";
    }

    private function buildOpenTasks(): string
    {
        $tasks = Task::open()->pluck('title')->implode(', ');

        return "## Tâches ouvertes (pour éviter les doublons)\n{$tasks}";
    }

    /**
     * @param  Collection<int, InboxItem>  $items
     */
    private function buildRecentRejections(Collection $items): string
    {
        if ($items->isEmpty()) {
            return '';
        }

        $rejections = InboxSuggestion::where('status', InboxSuggestionStatus::Rejected)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('inbox_item_id', $items->pluck('id'))
            ->get();

        if ($rejections->isEmpty()) {
            return "## Refus récents (30 derniers jours)\nAucun refus récent — rien à éviter de re-proposer.";
        }

        $lines = $rejections->map(fn (InboxSuggestion $rejection): string => "- item #{$rejection->inbox_item_id} : {$rejection->suggested_type->value} — ne jamais re-proposer.")->implode("\n");

        return "## Refus récents (30 derniers jours)\n{$lines}";
    }
}
