<?php

use App\Enums\GoalStatus;
use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\BrainDocument;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $goal = Goal::factory()->create();

    $this->get(route('goals.show', $goal))->assertRedirect(route('login'));
});

test('it shows the goal with its milestones and linked tasks', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['title' => 'Semi-marathon']);
    $milestone = Milestone::factory()->create(['goal_id' => $goal->id, 'title' => 'Courir 10km']);
    $task = Task::factory()->create(['goal_id' => $goal->id, 'title' => 'Sortie longue']);

    $this->get(route('goals.show', $goal))
        ->assertSee('Semi-marathon')
        ->assertSee('Courir 10km')
        ->assertSee('Sortie longue');
});

test('it adds and toggles a milestone', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create();

    Livewire::test('pages::goals.show', ['goal' => $goal])
        ->set('newMilestoneTitle', 'Courir 5km')
        ->call('addMilestone');

    $milestone = $goal->milestones()->where('title', 'Courir 5km')->first();
    expect($milestone)->not->toBeNull();
    expect($milestone->status)->toBe(MilestoneStatus::Pending);

    Livewire::test('pages::goals.show', ['goal' => $goal])
        ->call('toggleMilestone', $milestone->id);

    expect($milestone->fresh()->status)->toBe(MilestoneStatus::Done);
});

test('it toggles a linked task from the goal page', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create();
    $task = Task::factory()->create(['goal_id' => $goal->id, 'status' => 'todo']);

    Livewire::test('pages::goals.show', ['goal' => $goal])
        ->call('toggleTask', $task->id);

    expect($task->fresh()->status)->toBe(TaskStatus::Done);
});

test('weekly activity still counts a task that has since been archived', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create();
    $task = Task::factory()->done()->create(['goal_id' => $goal->id, 'completed_at' => now()]);
    $task->update(['archived_at' => now()]);

    $weeklyActivity = Livewire::test('pages::goals.show', ['goal' => $goal])->get('weeklyActivity');

    expect(array_sum($weeklyActivity))->toBe(1);
});

test('it updates the goal status', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['status' => 'active']);

    Livewire::test('pages::goals.show', ['goal' => $goal])
        ->call('updateStatus', 'paused');

    expect($goal->fresh()->status)->toBe(GoalStatus::Paused);
});

test('it edits the goal details', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['title' => 'Old title']);

    Livewire::test('pages::goals.show', ['goal' => $goal])
        ->call('openEditModal')
        ->set('editTitle', 'New title')
        ->call('saveEdit');

    expect($goal->fresh()->title)->toBe('New title');
});

test('it deletes the goal', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create();

    Livewire::test('pages::goals.show', ['goal' => $goal])
        ->call('delete')
        ->assertRedirect(route('goals.index'));

    expect(Goal::find($goal->id))->toBeNull();
});

test('it shows anchored vault documents when the vault is configured', function () {
    config(['brain.remote_url' => 'git@example.com:vault.git']);
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create();
    $document = BrainDocument::factory()->create(['title' => 'Plan entraînement semi']);
    $goal->brainDocuments()->attach($document);

    $this->get(route('goals.show', $goal))->assertSee($document->path);
});

test('the vault notes section is hidden when the vault is not configured', function () {
    config(['brain.remote_url' => null]);
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create();

    $this->get(route('goals.show', $goal))->assertDontSee('Notes du vault');
});

/**
 * Écran 8 du doc de refonte : le jalon qui vient est la seule ligne mise en
 * avant, parce que c'est la seule qu'on peut cocher aujourd'hui.
 */
test('the next milestone is the only one the sheet puts forward', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create();
    Milestone::factory()->for($goal)->create(['title' => '3 km sans pause', 'status' => 'done', 'position' => 0]);
    Milestone::factory()->for($goal)->create(['title' => '5 km sans pause', 'status' => 'pending', 'position' => 1]);
    Milestone::factory()->for($goal)->create(['title' => '10 km d\'une traite', 'status' => 'pending', 'position' => 2]);

    $html = Livewire::test('pages::goals.show', ['goal' => $goal])->html();

    expect(substr_count($html, 'Le suivant'))->toBe(1)
        ->and($html)->toContain('bg-(--acbg)')
        ->and($html)->toContain('1 / 3');
});

test('the journal only tells what the base actually knows', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['title' => 'Courir 10 km', 'created_at' => now()->subMonths(3)]);
    Milestone::factory()->for($goal)->create(['title' => '3 km sans pause', 'status' => 'done']);
    Task::factory()->for($goal)->create(['title' => 'Courir 5 km', 'status' => 'done', 'completed_at' => now()->subDay()]);

    Livewire::test('pages::goals.show', ['goal' => $goal])
        ->assertSee('Jalon « 3 km sans pause » coché.')
        ->assertSee('Tâche « Courir 5 km » cochée.')
        ->assertSee('Objectif posé.')
        ->assertSee('hier');
});

test('a goal with no activity says it in French rather than drawing a flat chart', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['created_at' => now()->subMonths(4)]);

    Livewire::test('pages::goals.show', ['goal' => $goal])
        ->assertSee('Rythme · 12 dernières semaines')
        ->assertSee('Aucune tâche terminée depuis trois mois.');
});
