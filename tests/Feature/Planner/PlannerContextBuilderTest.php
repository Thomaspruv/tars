<?php

use App\Models\Event;
use App\Models\Goal;
use App\Models\PlannerProposalItem;
use App\Models\Task;
use App\Support\Planner\PlannerContextBuilder;

test('assembles open tasks, active goals, already scheduled items and pending proposals', function () {
    $goal = Goal::factory()->create(['title' => 'Rénover la salle de bain', 'target_date' => '2026-09-01']);
    Task::factory()->create(['title' => 'Tâche ouverte', 'status' => 'todo', 'goal_id' => $goal->id, 'priority' => 'p1']);

    $scheduledTask = Task::factory()->create(['title' => 'Déjà planifiée', 'status' => 'todo', 'scheduled_date' => now()->addDays(2)]);
    Event::factory()->create(['title' => 'RDV syndic', 'starts_at' => now()->addDays(3)]);

    $item = PlannerProposalItem::factory()->create(['proposed_scheduled_date' => now()->addDays(4)]);

    $context = (new PlannerContextBuilder)->build(now(), now()->addDays(7));

    expect($context)
        ->toContain('## Tâches ouvertes')
        ->toContain('Tâche ouverte')
        ->toContain('## Objectifs actifs')
        ->toContain('Rénover la salle de bain')
        ->toContain('## Déjà planifié sur la période')
        ->toContain('Déjà planifiée')
        ->toContain('RDV syndic')
        ->toContain('## Propositions en attente')
        ->toContain($item->task->title);

    expect($scheduledTask)->not->toBeNull();
});

test('shows empty-state sections when there is nothing to report', function () {
    $context = (new PlannerContextBuilder)->build(now(), now()->addDays(7));

    expect($context)
        ->toContain('Aucune tâche ouverte.')
        ->toContain('Aucun objectif actif.')
        ->toContain('Rien de déjà planifié.')
        ->not->toContain('Propositions en attente');
});
