<?php

namespace App\Support\Planner;

use App\Enums\GoalStatus;
use App\Enums\PlannerProposalStatus;
use App\Models\Event;
use App\Models\Goal;
use App\Models\PlannerProposalItem;
use App\Models\Task;
use Carbon\CarbonInterface;

class PlannerContextBuilder
{
    public function build(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return implode("\n\n", array_filter([
            $this->buildOpenTasks(),
            $this->buildActiveGoals(),
            $this->buildAlreadyScheduled($periodStart, $periodEnd),
            $this->buildPendingProposals(),
        ]));
    }

    private function buildOpenTasks(): string
    {
        $tasks = Task::open()->topLevel()->with(['goal', 'entity'])->get();

        if ($tasks->isEmpty()) {
            return "## Tâches ouvertes\nAucune tâche ouverte.";
        }

        $lines = $tasks->map(function (Task $task): string {
            $due = $task->due_date ? $task->due_date->translatedFormat('d M') : 'aucune';
            $scheduled = $task->scheduled_date ? $task->scheduled_date->translatedFormat('d M') : 'aucune';
            $priority = $task->priority->value ?? 'aucune';
            $anchors = collect([$task->goal?->title, $task->entity?->name])->filter()->implode(', ');

            return "- **#{$task->id} {$task->title}** — priorité : {$priority}, échéance : {$due}, planifiée : {$scheduled}".($anchors ? " [{$anchors}]" : '');
        })->implode("\n");

        return "## Tâches ouvertes\n{$lines}";
    }

    private function buildActiveGoals(): string
    {
        $goals = Goal::where('status', GoalStatus::Active)->get();

        if ($goals->isEmpty()) {
            return "## Objectifs actifs\nAucun objectif actif.";
        }

        $lines = $goals->map(function (Goal $goal): string {
            $target = $goal->target_date ? $goal->target_date->translatedFormat('d M Y') : 'aucune';

            return "- {$goal->title} — cible : {$target}, revue : {$goal->review_frequency->value}";
        })->implode("\n");

        return "## Objectifs actifs\n{$lines}";
    }

    private function buildAlreadyScheduled(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $tasks = Task::open()
            ->whereBetween('scheduled_date', [$periodStart, $periodEnd])
            ->get()
            ->map(fn (Task $task): string => "- Tâche : {$task->title} — {$task->scheduled_date->translatedFormat('d M')}");

        $events = Event::whereBetween('starts_at', [$periodStart, $periodEnd])
            ->get()
            ->map(fn (Event $event): string => "- Événement : {$event->title} — {$event->starts_at->translatedFormat('d M')}");

        $lines = $tasks->merge($events);

        if ($lines->isEmpty()) {
            return "## Déjà planifié sur la période\nRien de déjà planifié.";
        }

        return "## Déjà planifié sur la période\n".$lines->implode("\n");
    }

    private function buildPendingProposals(): string
    {
        $items = PlannerProposalItem::where('status', PlannerProposalStatus::Pending->value)
            ->with('task')
            ->get();

        if ($items->isEmpty()) {
            return '';
        }

        $lines = $items->map(fn (PlannerProposalItem $item): string => "- #{$item->task_id} {$item->task->title} déjà proposée pour le {$item->proposed_scheduled_date->translatedFormat('d M')} — ne pas re-proposer.")->implode("\n");

        return "## Propositions en attente d'un run précédent\n{$lines}";
    }
}
