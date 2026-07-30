<?php

use App\Enums\TaskStatus;
use App\Models\BrainDocument;
use App\Models\Decision;
use App\Models\Event;
use App\Models\Goal;
use App\Models\JournalEntry;
use App\Models\Questionnaire;
use App\Models\QuestionnaireRun;
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

test('still counts a completed task that has since been archived in the period stats', function () {
    $task = Task::factory()->create(['status' => TaskStatus::Done, 'completed_at' => now()->subDays(2)]);
    $task->update(['archived_at' => now()]);

    $context = (new ReviewContextBuilder)->build(now()->subDays(7), now());

    expect($context)->toContain('Tâches terminées : 1');
});

test('excludes subtasks from the orphan-ratio stats', function () {
    $goal = Goal::factory()->create(['status' => 'active']);
    $parent = Task::factory()->create(['status' => TaskStatus::Todo, 'goal_id' => $goal->id]);
    Task::factory()->subtaskOf($parent)->create(['status' => TaskStatus::Todo, 'goal_id' => null]);

    $context = (new ReviewContextBuilder)->build(now()->subDays(7), now());

    // Only the top-level, goal-linked task counts: 0 orphaned out of 1 open.
    expect($context)->toContain('Tâches orphelines (sans objectif) : 0/1 tâches ouvertes (0%)');
});

test('reports empty sections gracefully when there is no data', function () {
    $context = (new ReviewContextBuilder)->build(now()->subDays(7), now());

    expect($context)
        ->toContain('Aucun objectif actif.')
        ->toContain('Aucune échéance dans les 14 prochains jours.')
        ->toContain('Aucune décision récente.')
        ->toContain('Aucune note récente.')
        ->toContain('## Journal de la période')
        ->toContain('Aucune entrée de journal sur cette période.')
        ->toContain('## Dernier bilan complété')
        ->toContain("Aucun bilan complété pour l'instant.")
        ->toContain('Aucun document de profil.');
});

test('includes the journal mood average and entries of the period', function () {
    JournalEntry::factory()->create(['date' => today()->subDays(2), 'mood' => 8, 'summary' => 'Semaine dense mais bonne.']);
    JournalEntry::factory()->create(['date' => today()->subDays(1), 'mood' => 6, 'summary' => 'Un peu fatigué.']);

    $context = (new ReviewContextBuilder)->build(now()->subDays(7), now());

    expect($context)
        ->toContain('## Journal de la période')
        ->toContain('Mood moyen : 7')
        ->toContain('2 jour(s) rempli(s)')
        ->toContain('Semaine dense mais bonne.')
        ->toContain('Un peu fatigué.');
});

test('includes the last completed questionnaire run with its answers', function () {
    $questionnaire = Questionnaire::factory()->create(['name' => 'Bilan mensuel']);
    $run = QuestionnaireRun::factory()->completed()->create(['questionnaire_id' => $questionnaire->id, 'completed_at' => now()->subDay()]);
    $run->answers()->create(['question_text' => 'Satisfaction', 'type' => 'scale', 'answer_numeric' => 8]);

    $context = (new ReviewContextBuilder)->build(now()->subDays(7), now());

    expect($context)
        ->toContain('## Dernier bilan complété')
        ->toContain('Bilan mensuel')
        ->toContain('Satisfaction : 8/10');
});
