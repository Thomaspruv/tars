<?php

use App\Models\Entity;
use App\Models\Event;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 8, 1));
    $this->actingAs(User::factory()->create());
});

afterEach(fn () => Carbon::setTestNow());

test('renders events on the currently displayed month', function () {
    Event::factory()->create(['title' => 'Réunion équipe', 'starts_at' => Carbon::create(2026, 8, 15, 10, 0)]);

    Livewire::test('pages::calendar')->assertSee('Réunion équipe');
});

test('previousMonth and nextMonth navigate across months', function () {
    Event::factory()->create(['title' => 'Événement juillet', 'starts_at' => Carbon::create(2026, 7, 10, 9, 0)]);

    Livewire::test('pages::calendar')
        ->assertDontSee('Événement juillet')
        ->assertSet('month', '2026-08')
        ->call('previousMonth')
        ->assertSet('month', '2026-07')
        ->assertSee('Événement juillet')
        ->call('nextMonth')
        ->assertSet('month', '2026-08')
        ->assertDontSee('Événement juillet');
});

test('goToToday resets the displayed month', function () {
    Livewire::test('pages::calendar')
        ->call('nextMonth')
        ->assertSet('month', '2026-09')
        ->call('goToToday')
        ->assertSet('month', '2026-08');
});

/**
 * Écran 10 du doc : le mois compte la charge. Il montre donc les tâches —
 * « 2 tâches · 1 événement » — là où il n'affichait que des barres
 * d'événements et laissait croire aux journées vides.
 */
test('the month counts the load of each day, tasks included', function () {
    Task::factory()->count(2)->create(['scheduled_date' => Carbon::create(2026, 8, 15)]);
    Event::factory()->create(['title' => 'Démo client', 'starts_at' => Carbon::create(2026, 8, 15, 10, 0)]);

    Livewire::test('pages::calendar')
        ->assertSee('2 tâches · 1 événement')
        ->assertSee('10:00 Démo client');
});

test('a day shows two lines at most, and the deadline wins over the rest', function () {
    Event::factory()->create(['title' => 'Démo client', 'starts_at' => Carbon::create(2026, 8, 12, 10, 0)]);
    Task::factory()->create(['title' => 'Taxe foncière', 'due_date' => Carbon::create(2026, 8, 12)]);

    $days = Livewire::test('pages::calendar')->instance()->days();

    // Une échéance est une date qui ne se déplace pas : elle passe devant un
    // rendez-vous, qui passe devant une tâche simplement planifiée.
    expect($days['2026-08-12']['notable']['kind'])->toBe('deadline')
        ->and($days['2026-08-12']['notable']['label'])->toBe('Taxe foncière')
        ->and($days['2026-08-12']['deadlines'])->toBe(1);
});

test('a pending milestone falls on the month as a deadline', function () {
    $goal = Goal::factory()->create();
    Milestone::factory()->for($goal)->create([
        'title' => 'trois devis',
        'target_date' => Carbon::create(2026, 8, 30),
        'status' => 'pending',
    ]);

    Livewire::test('pages::calendar')
        ->assertSee('Jalon : trois devis')
        ->assertSee('1 échéance');
});

test('a heavy day is tinted, a quiet one is not', function () {
    Task::factory()->count(6)->create(['scheduled_date' => Carbon::create(2026, 8, 18)]);
    Task::factory()->create(['scheduled_date' => Carbon::create(2026, 8, 19)]);

    $component = Livewire::test('pages::calendar');

    expect($component->instance()->isHeavy('2026-08-18'))->toBeTrue()
        ->and($component->instance()->isHeavy('2026-08-19'))->toBeFalse()
        ->and($component->html())->toContain('Journée chargée');
});

test('a multi-day event weighs on every day it covers, not just its first', function () {
    Event::factory()->create([
        'starts_at' => Carbon::create(2026, 8, 5, 9, 0),
        'ends_at' => Carbon::create(2026, 8, 7, 18, 0),
    ]);

    $days = Livewire::test('pages::calendar')->instance()->days();

    expect($days['2026-08-05']['events'])->toBe(1)
        ->and($days['2026-08-06']['events'])->toBe(1)
        ->and($days['2026-08-07']['events'])->toBe(1)
        ->and($days['2026-08-08']['events'])->toBe(0);
});

test('the grid runs to the Sunday of the last week, so no cell is missing', function () {
    $weeks = Livewire::test('pages::calendar')->instance()->weeks();

    $all = collect($weeks)->flatten(1);

    expect($all->first()->toDateString())->toBe('2026-07-27')
        ->and($all->last()->toDateString())->toBe('2026-09-06')
        ->and($all)->toHaveCount(42);
});

test('opening the edit modal populates the form from the event', function () {
    $goal = Goal::factory()->create(['status' => 'active']);
    $entity = Entity::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Point hebdo',
        'notes' => 'Ordre du jour',
        'starts_at' => Carbon::create(2026, 8, 5, 14, 0),
        'ends_at' => Carbon::create(2026, 8, 5, 15, 0),
        'goal_id' => $goal->id,
        'entity_id' => $entity->id,
    ]);

    Livewire::test('pages::calendar')
        ->call('openEditEventModal', $event->id)
        ->assertSet('showEditEventModal', true)
        ->assertSet('eventTitle', 'Point hebdo')
        ->assertSet('eventNotes', 'Ordre du jour')
        ->assertSet('eventStartsAt', '2026-08-05T14:00')
        ->assertSet('eventEndsAt', '2026-08-05T15:00')
        ->assertSet('eventGoalId', $goal->id)
        ->assertSet('eventEntityId', $entity->id);
});

test('saving the edit modal updates the event', function () {
    $event = Event::factory()->create([
        'title' => 'Ancien titre',
        'starts_at' => Carbon::create(2026, 8, 5, 14, 0),
        'ends_at' => null,
    ]);

    Livewire::test('pages::calendar')
        ->call('openEditEventModal', $event->id)
        ->set('eventTitle', 'Nouveau titre')
        ->set('eventStartsAt', '2026-08-06T09:00')
        ->call('saveEvent')
        ->assertSet('showEditEventModal', false);

    $event->refresh();
    expect($event->title)->toBe('Nouveau titre')
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe('2026-08-06 09:00');
});

test('the edit modal requires a title and a valid start date', function () {
    $event = Event::factory()->create();

    Livewire::test('pages::calendar')
        ->call('openEditEventModal', $event->id)
        ->set('eventTitle', '')
        ->call('saveEvent')
        ->assertHasErrors(['eventTitle' => 'required']);
});
