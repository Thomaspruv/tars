<?php

use App\Enums\TaskStatus;
use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('tasks.index'))->assertRedirect(route('login'));
});

test('it lists open tasks and hides done ones', function () {
    $this->actingAs(User::factory()->create());

    $open = Task::factory()->create(['title' => 'A faire']);
    $done = Task::factory()->done()->create(['title' => 'Deja fait']);

    $this->get(route('tasks.index'))
        ->assertSee('A faire')
        ->assertDontSee('Deja fait');
});

test('it filters overdue tasks', function () {
    $this->actingAs(User::factory()->create());

    $overdue = Task::factory()->create(['title' => 'En retard', 'due_date' => today()->subDays(2)]);
    $future = Task::factory()->create(['title' => 'Pas encore', 'scheduled_date' => today()->addWeek()]);

    Livewire::test('pages::tasks')
        ->call('setFilter', 'overdue')
        ->assertSee('En retard')
        ->assertDontSee('Pas encore');
});

test('it filters tasks without a goal', function () {
    $this->actingAs(User::factory()->create());

    $orphan = Task::factory()->create(['title' => 'Sans objectif', 'goal_id' => null]);
    $goal = Goal::factory()->create();
    $linked = Task::factory()->create(['title' => 'Avec objectif', 'goal_id' => $goal->id]);

    Livewire::test('pages::tasks')
        ->call('setFilter', 'orphan')
        ->assertSee('Sans objectif')
        ->assertDontSee('Avec objectif');
});

test('it filters tasks by goal', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['title' => 'Semi-marathon']);
    $matching = Task::factory()->create(['title' => 'Sortie longue', 'goal_id' => $goal->id]);
    $other = Task::factory()->create(['title' => 'Autre tache']);

    Livewire::test('pages::tasks')
        ->set('goalId', $goal->id)
        ->assertSee('Sortie longue')
        ->assertDontSee('Autre tache');
});

test('it computes the orphan ratio', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create();
    Task::factory()->create(['goal_id' => $goal->id]);
    Task::factory()->create(['goal_id' => null]);
    Task::factory()->create(['goal_id' => null]);

    Livewire::test('pages::tasks')
        ->assertSee('2/3 sans objectif · 67%');
});

test('it toggles a task', function () {
    $this->actingAs(User::factory()->create());

    $task = Task::factory()->create(['status' => 'todo']);

    Livewire::test('pages::tasks')->call('toggleTask', $task->id);

    expect($task->fresh()->status)->toBe(TaskStatus::Done);
});
