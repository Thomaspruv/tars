<?php

use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('goals.index'))->assertRedirect(route('login'));
});

test('it lists goals', function () {
    $this->actingAs(User::factory()->create());

    Goal::factory()->create(['title' => 'Renover SDB']);

    $this->get(route('goals.index'))->assertSee('Renover SDB');
});

test('it shows an empty state when there are no goals', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('goals.index'))
        ->assertSee('Aucun objectif ouvert.', false)
        ->assertSee('Poser un objectif');
});

test('it creates a goal', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::goals')
        ->call('openCreateModal')
        ->set('title', 'Semi-marathon')
        ->call('createGoal')
        ->assertSet('showCreateModal', false);

    expect(Goal::where('title', 'Semi-marathon')->exists())->toBeTrue();
});

test('it requires a title to create a goal', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::goals')
        ->call('openCreateModal')
        ->set('title', '')
        ->call('createGoal')
        ->assertHasErrors(['title']);
});

test('the three states are a filter, not three stacked lists', function () {
    $this->actingAs(User::factory()->create());

    Goal::factory()->create(['title' => 'Courir 10 km', 'status' => 'active']);
    Goal::factory()->create(['title' => 'Apprendre le piano', 'status' => 'paused']);
    Goal::factory()->create(['title' => 'Refaire la SDB', 'status' => 'done']);

    Livewire::test('pages::goals')
        ->assertSee('Courir 10 km')
        ->assertDontSee('Apprendre le piano')
        ->call('showView', 'en-pause')
        ->assertSee('Apprendre le piano')
        ->assertDontSee('Courir 10 km')
        ->call('showView', 'atteints')
        ->assertSee('Refaire la SDB');
});

test('an unknown view falls back on what is in progress', function () {
    $this->actingAs(User::factory()->create());

    Goal::factory()->create(['title' => 'Courir 10 km', 'status' => 'active']);

    Livewire::test('pages::goals', ['view' => 'trimestre'])
        ->assertSet('view', 'en-cours')
        ->assertSee('Courir 10 km');
});

/**
 * La carte dit quand l'objectif a bougé pour la dernière fois, et l'objectif
 * qui dort depuis trois semaines passe devant — sans rouge.
 */
test('a goal that has not moved for three weeks leads the grid and says so', function () {
    $this->actingAs(User::factory()->create());

    $awake = Goal::factory()->create(['title' => 'Lancer l\'abonnement', 'created_at' => now()->subMonths(2)]);
    Task::factory()->for($awake)->create([
        'status' => 'done',
        'completed_at' => now()->subDay(),
        // Poser une tâche est un mouvement aussi : sans cette date, la carte
        // dirait « a bougé aujourd'hui » à cause de la création.
        'created_at' => now()->subDay(),
    ]);

    $asleep = Goal::factory()->create(['title' => 'Courir 10 km', 'created_at' => now()->subMonths(4)]);

    Livewire::test('pages::goals')
        ->assertSeeInOrder(['Courir 10 km', 'Lancer l\'abonnement'])
        ->assertSee('sem. sans mouvement')
        ->assertSee('a bougé hier')
        // Le rail dit la même chose en une phrase, et ouvre la fiche.
        ->assertSee('n\'a pas bougé depuis')
        ->assertSee(route('goals.show', $asleep), false);
});

test('the rail stays quiet when everything has moved', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['created_at' => now()->subMonths(4)]);
    Task::factory()->for($goal)->create([
        'status' => 'done',
        'completed_at' => now()->subDays(2),
        'created_at' => now()->subDays(2),
    ]);

    Livewire::test('pages::goals')->assertDontSee('n\'a pas bougé depuis');
});

test('the horizon of a goal is read from its deadline, never from a field of its own', function () {
    expect(Goal::factory()->make(['target_date' => null])->horizon_label)->toBe('sans échéance')
        ->and(Goal::factory()->make(['target_date' => today()->subWeek()])->horizon_label)->toBe('échéance dépassée')
        ->and(Goal::factory()->make(['target_date' => today()->addMonths(2)])->horizon_label)->toBe('ce trimestre')
        ->and(Goal::factory()->make(['target_date' => today()->addMonths(8)])->horizon_label)->toBe('cette année')
        ->and(Goal::factory()->make(['target_date' => today()->addYears(3)])->horizon_label)->toBe('au-delà d\'un an');
});
