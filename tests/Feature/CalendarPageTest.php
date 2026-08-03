<?php

use App\Models\Entity;
use App\Models\Event;
use App\Models\Goal;
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

test('does not show tasks on the calendar', function () {
    Task::factory()->create(['title' => 'Tâche fantôme', 'scheduled_date' => Carbon::create(2026, 8, 15)]);

    Livewire::test('pages::calendar')->assertDontSee('Tâche fantôme');
});

test('a multi-day event spans every column it covers, not just its start day', function () {
    $event = Event::factory()->create([
        'starts_at' => Carbon::create(2026, 8, 5, 9, 0), // Wednesday
        'ends_at' => Carbon::create(2026, 8, 7, 18, 0), // Friday
    ]);

    $weeks = Livewire::test('pages::calendar')->instance()->weeks();

    $week = collect($weeks)->first(fn (array $w): bool => collect($w['days'])->contains(fn (Carbon $d): bool => $d->toDateString() === '2026-08-05'));

    $placement = collect($week['laneRows'][0])->first(fn ($slot): bool => is_array($slot) && $slot['event']->id === $event->id);

    expect($placement)->not->toBeNull()
        ->and($placement['colSpan'])->toBe(3);
});

test('a multi-day event spanning a week boundary is clipped to each week', function () {
    // Aug 1 2026 is a Saturday; the grid's Monday-start week containing it
    // runs Jul 27 -> Aug 2, so this event (Fri -> Wed the week after) crosses
    // two weeks and must be clipped separately in each.
    $event = Event::factory()->create([
        'starts_at' => Carbon::create(2026, 7, 31, 9, 0), // Friday, week 1
        'ends_at' => Carbon::create(2026, 8, 5, 18, 0), // Wednesday, week 2
    ]);

    $weeks = collect(Livewire::test('pages::calendar')->instance()->weeks());

    $firstWeek = $weeks->first(fn (array $w): bool => collect($w['days'])->contains(fn (Carbon $d): bool => $d->toDateString() === '2026-07-31'));
    $secondWeek = $weeks->first(fn (array $w): bool => collect($w['days'])->contains(fn (Carbon $d): bool => $d->toDateString() === '2026-08-05'));

    $firstPlacement = collect($firstWeek['laneRows'][0])->first(fn ($slot): bool => is_array($slot) && $slot['event']->id === $event->id);
    $secondPlacement = collect($secondWeek['laneRows'][0])->first(fn ($slot): bool => is_array($slot) && $slot['event']->id === $event->id);

    expect($firstPlacement['colSpan'])->toBe(3) // Fri, Sat, Sun — clipped at the week's end
        ->and($secondPlacement['colSpan'])->toBe(3); // Mon, Tue, Wed — clipped at the week's start
});

test('only the segment holding the true start/end is marked as such, for rounded corners and the time label', function () {
    // Straddles a week boundary with exactly one day on each side, so both
    // segments render at colSpan 1 — a prior bug showed the start time on
    // both, implying the event "started" again on the continuation day.
    $event = Event::factory()->create([
        'starts_at' => Carbon::create(2026, 8, 16, 9, 0), // Sunday, week 2
        'ends_at' => Carbon::create(2026, 8, 17, 20, 0), // Monday, week 3
    ]);

    $weeks = collect(Livewire::test('pages::calendar')->instance()->weeks());

    $firstWeek = $weeks->first(fn (array $w): bool => collect($w['days'])->contains(fn (Carbon $d): bool => $d->toDateString() === '2026-08-16'));
    $secondWeek = $weeks->first(fn (array $w): bool => collect($w['days'])->contains(fn (Carbon $d): bool => $d->toDateString() === '2026-08-17'));

    $firstPlacement = collect($firstWeek['laneRows'][0])->first(fn ($slot): bool => is_array($slot) && $slot['event']->id === $event->id);
    $secondPlacement = collect($secondWeek['laneRows'][0])->first(fn ($slot): bool => is_array($slot) && $slot['event']->id === $event->id);

    expect($firstPlacement['isStart'])->toBeTrue()
        ->and($firstPlacement['isEnd'])->toBeFalse()
        ->and($secondPlacement['isStart'])->toBeFalse()
        ->and($secondPlacement['isEnd'])->toBeTrue();
});

test('caps visible events per week and reports the rest as an overflow count', function () {
    Event::factory()->count(5)->create(['starts_at' => Carbon::create(2026, 8, 10, 9, 0), 'ends_at' => null]);

    $weeks = collect(Livewire::test('pages::calendar')->instance()->weeks());
    $week = $weeks->first(fn (array $w): bool => collect($w['days'])->contains(fn (Carbon $d): bool => $d->toDateString() === '2026-08-10'));

    expect($week['laneRows'])->toHaveCount(3)
        ->and($week['overflow']['2026-08-10'])->toBe(2);
});

test('hovering an event shows its date range and goal in a tooltip', function () {
    $goal = Goal::factory()->create(['title' => 'Refonte site', 'status' => 'active']);
    Event::factory()->create([
        'title' => 'Point hebdo',
        'starts_at' => Carbon::create(2026, 8, 5, 14, 0),
        'ends_at' => Carbon::create(2026, 8, 5, 15, 0),
        'goal_id' => $goal->id,
    ]);

    Livewire::test('pages::calendar')
        ->assertSee('05 août à 14:00')
        ->assertSee('#refonte-site');
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
