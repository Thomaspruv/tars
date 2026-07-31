<?php

use App\Enums\TaskStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\InboxItem;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use App\Support\Review\ReviewSettings;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

afterEach(fn () => Carbon::setTestNow());

test('guests are redirected to the login page', function () {
    $this->get(route('today'))->assertRedirect(route('login'));
});

test('it lists tasks scheduled for today and overdue tasks, excluding future and old done tasks', function () {
    $this->actingAs(User::factory()->create());

    $scheduledToday = Task::factory()->create(['title' => 'Scheduled today', 'scheduled_date' => today()]);
    $overdue = Task::factory()->create(['title' => 'Overdue', 'due_date' => today()->subDay()]);
    $completedToday = Task::factory()->done()->create(['title' => 'Done today', 'scheduled_date' => today(), 'completed_at' => now()]);
    $completedYesterday = Task::factory()->done()->create(['title' => 'Done yesterday', 'scheduled_date' => today(), 'completed_at' => now()->subDay()]);
    $future = Task::factory()->create(['title' => 'Future', 'scheduled_date' => today()->addWeek()]);

    $response = $this->get(route('today'));

    $response->assertOk()
        ->assertSee($scheduledToday->title)
        ->assertSee($overdue->title)
        ->assertSee($completedToday->title)
        ->assertDontSee($completedYesterday->title)
        ->assertDontSee($future->title);
});

test('it toggles a task done and back to todo', function () {
    $this->actingAs(User::factory()->create());

    $task = Task::factory()->create(['scheduled_date' => today(), 'status' => 'todo']);

    Livewire::test('pages::today')
        ->call('toggleTask', $task->id);

    expect($task->fresh()->status)->toBe(TaskStatus::Done);
    expect($task->fresh()->completed_at)->not->toBeNull();

    Livewire::test('pages::today')
        ->call('toggleTask', $task->id);

    expect($task->fresh()->status)->toBe(TaskStatus::Todo);
    expect($task->fresh()->completed_at)->toBeNull();
});

test('it lists events for the next 7 days', function () {
    $this->actingAs(User::factory()->create());

    $soon = Event::factory()->create(['title' => 'Soon', 'starts_at' => today()->addDays(2)]);
    $tooFar = Event::factory()->create(['title' => 'Too far', 'starts_at' => today()->addDays(10)]);

    $this->get(route('today'))
        ->assertSee($soon->title)
        ->assertDontSee($tooFar->title);
});

test('it shows pinned lists and toggles list items', function () {
    $this->actingAs(User::factory()->create());

    $pinned = Checklist::factory()->create(['name' => 'Courses', 'is_pinned' => true]);
    $item = ChecklistItem::factory()->create(['list_id' => $pinned->id, 'content' => 'Lait']);

    $this->get(route('today'))
        ->assertSee('Courses')
        ->assertSee('Lait');

    Livewire::test('pages::today')->call('toggleListItem', $item->id);

    expect($item->fresh()->checked_at)->not->toBeNull();
});

test('it shows unpinned lists under "Autres listes" and toggles their items too', function () {
    $this->actingAs(User::factory()->create());

    $unpinned = Checklist::factory()->create(['name' => 'Livres à lire', 'is_pinned' => false]);
    $item = ChecklistItem::factory()->create(['list_id' => $unpinned->id, 'content' => 'Dune']);

    $this->get(route('today'))
        ->assertSee('Autres listes')
        ->assertSee('Livres à lire')
        ->assertSee('Dune');

    Livewire::test('pages::today')->call('toggleListItem', $item->id);

    expect($item->fresh()->checked_at)->not->toBeNull();
});

test('does not show the "Autres listes" section when every list is pinned', function () {
    $this->actingAs(User::factory()->create());

    Checklist::factory()->create(['name' => 'Courses', 'is_pinned' => true]);

    $this->get(route('today'))->assertDontSee('Autres listes');
});

test('pinning an unpinned list moves it out of "Autres listes"', function () {
    $this->actingAs(User::factory()->create());

    $list = Checklist::factory()->create(['name' => 'Livres à lire', 'is_pinned' => false]);

    Livewire::test('pages::today')->call('pinList', $list->id);

    expect($list->fresh()->is_pinned)->toBeTrue();
});

test('shows a banner when an uncompleted review exists', function () {
    $this->actingAs(User::factory()->create());

    Review::factory()->create(['completed_at' => null]);

    $this->get(route('today'))->assertSee('prête à lire', false);
});

test('does not show the pending review banner once completed', function () {
    $this->actingAs(User::factory()->create());

    Review::factory()->create(['completed_at' => now()]);

    $this->get(route('today'))->assertDontSee('prête à lire', false);
});

test('shows the review as due today when the configured time has not passed yet', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-07 10:00:00')); // a Sunday, before the default 17:00
    $this->actingAs(User::factory()->create());

    expect(Livewire::test('pages::today')->get('daysUntilReview'))->toBe(0);
});

test('shows the review as due next sunday once the configured time has passed', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-07 18:00:00')); // a Sunday, after the default 17:00
    $this->actingAs(User::factory()->create());

    expect(Livewire::test('pages::today')->get('daysUntilReview'))->toBe(7);
});

test('respects a custom weekly review time', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-07 19:00:00')); // a Sunday at 19:00
    (new ReviewSettings)->updateWeeklyTime('20:00');
    $this->actingAs(User::factory()->create());

    expect(Livewire::test('pages::today')->get('daysUntilReview'))->toBe(0);
});

test('shows an inbox pending nudge when there are unprocessed inbox items', function () {
    $this->actingAs(User::factory()->create());

    InboxItem::factory()->create(['processed_at' => null]);

    $this->get(route('today'))->assertSee('1 élément en inbox');
});

test('does not show the inbox pending nudge when there are no unprocessed inbox items', function () {
    $this->actingAs(User::factory()->create());

    InboxItem::factory()->create(['processed_at' => now()]);

    $this->get(route('today'))->assertDontSee('en inbox');
});

test('groups overdue tasks under an "En retard" heading', function () {
    $this->actingAs(User::factory()->create());

    $overdue = Task::factory()->create(['title' => 'Overdue task', 'due_date' => today()->subDay()]);
    $dueToday = Task::factory()->create(['title' => 'Due today task', 'scheduled_date' => today()]);

    $this->get(route('today'))
        ->assertSee('En retard')
        ->assertSee($overdue->title)
        ->assertSee($dueToday->title);
});

test('does not show the "En retard" heading when there is no overdue task', function () {
    $this->actingAs(User::factory()->create());

    Task::factory()->create(['title' => 'Due today only', 'scheduled_date' => today()]);

    $this->get(route('today'))->assertDontSee('En retard');
});

test('shows completed-today tasks inside the "Terminées aujourd\'hui" section', function () {
    $this->actingAs(User::factory()->create());

    $done = Task::factory()->done()->create(['title' => 'Done today task', 'scheduled_date' => today(), 'completed_at' => now()]);

    $this->get(route('today'))
        ->assertSee("Terminées aujourd'hui", false)
        ->assertSee($done->title);
});

test('lists open tasks with no scheduled date and no due date under "Sans date"', function () {
    $this->actingAs(User::factory()->create());

    $unscheduled = Task::factory()->create(['title' => 'No date task', 'scheduled_date' => null, 'due_date' => null]);
    Task::factory()->create(['title' => 'Scheduled task', 'scheduled_date' => today()]);
    Task::factory()->create(['title' => 'Due later task', 'scheduled_date' => null, 'due_date' => today()->addWeek()]);
    Task::factory()->done()->create(['title' => 'Done no date task', 'scheduled_date' => null, 'due_date' => null]);

    $unscheduledTasks = Livewire::test('pages::today')->get('unscheduledTasks');

    expect($unscheduledTasks->pluck('id')->all())->toBe([$unscheduled->id]);

    $this->get(route('today'))
        ->assertSee('Sans date')
        ->assertSee($unscheduled->title);
});

test('does not show the "Sans date" section when every open task has a date', function () {
    $this->actingAs(User::factory()->create());

    Task::factory()->create(['title' => 'Scheduled task', 'scheduled_date' => today()]);

    $this->get(route('today'))->assertDontSee('Sans date');
});

test('scheduling an unscheduled task for today moves it into "Tâches du jour"', function () {
    $this->actingAs(User::factory()->create());

    $task = Task::factory()->create(['title' => 'No date task', 'scheduled_date' => null, 'due_date' => null]);

    Livewire::test('pages::today')->call('scheduleForToday', $task->id);

    expect($task->fresh()->scheduled_date->isToday())->toBeTrue();
});
