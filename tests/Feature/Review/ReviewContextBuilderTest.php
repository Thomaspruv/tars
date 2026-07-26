<?php

use App\Enums\TaskStatus;
use App\Models\BrainDocument;
use App\Models\Decision;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Task;
use App\Support\Review\ReviewContextBuilder;

test('includes stats, active goals, deadlines, decisions, notes and profile', function () {
    $goal = Goal::factory()->create(['title' => 'Courir un semi-marathon', 'status' => 'active']);

    Task::factory()->create(['status' => TaskStatus::Done, 'completed_at' => now()->subDays(2), 'goal_id' => $goal->id]);
    Task::factory()->create(['status' => TaskStatus::Todo, 'goal_id' => null, 'due_date' => today()->addDays(3)]);

    Event::factory()->create(['title' => 'Rendez-vous médecin', 'starts_at' => now()->addDays(5)]);

    Decision::factory()->create(['content' => 'On garde le rythme ?', 'decided_at' => now()->subWeeks(2)]);

    BrainDocument::factory()->create(['path' => 'notes/course.md', 'title' => 'Note de course', 'mtime' => now()]);
    BrainDocument::factory()->create(['path' => 'Profil/perso.md', 'title' => 'Perso', 'content' => 'Aime courir le matin.']);

    $context = (new ReviewContextBuilder)->build(now()->subDays(7), now());

    expect($context)
        ->toContain('## Statistiques de la période')
        ->toContain('## Objectifs actifs')
        ->toContain('Courir un semi-marathon')
        ->toContain('## Échéances à venir (J+14)')
        ->toContain('Rendez-vous médecin')
        ->toContain('## Décisions récentes (6 dernières semaines)')
        ->toContain('On garde le rythme ?')
        ->toContain('## Notes récentes du cerveau')
        ->toContain('Note de course')
        ->toContain('## Profil')
        ->toContain('Aime courir le matin.');
});

test('reports empty sections gracefully when there is no data', function () {
    $context = (new ReviewContextBuilder)->build(now()->subDays(7), now());

    expect($context)
        ->toContain('Aucun objectif actif.')
        ->toContain('Aucune échéance dans les 14 prochains jours.')
        ->toContain('Aucune décision récente.')
        ->toContain('Aucune note récente.')
        ->toContain('Aucun document de profil.');
});
