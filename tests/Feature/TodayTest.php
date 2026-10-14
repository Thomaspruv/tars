<?php

use App\Enums\GoalStatus;
use App\Enums\TaskStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
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

test('summarizes the unpinned lists without listing their items', function () {
    $this->actingAs(User::factory()->create());

    $unpinned = Checklist::factory()->create(['name' => 'Livres à lire', 'is_pinned' => false]);
    ChecklistItem::factory()->create(['list_id' => $unpinned->id, 'content' => 'Dune']);

    $this->get(route('today'))
        ->assertSee('Livres à lire')
        ->assertDontSee('Dune');
});

test('adds an item to a pinned list from the day screen', function () {
    $this->actingAs(User::factory()->create());

    $list = Checklist::factory()->create(['name' => 'Courses', 'is_pinned' => true]);

    Livewire::test('pages::today')->call('addListItem', $list->id, 'Pain');

    expect($list->items()->pluck('content')->all())->toBe(['Pain']);
});

test('shows the agent block when an uncompleted review exists', function () {
    $this->actingAs(User::factory()->create());

    Review::factory()->create(['completed_at' => null]);

    $this->get(route('today'))->assertSee('Revue prête', false);
});

test('does not show the pending review block once completed', function () {
    $this->actingAs(User::factory()->create());

    Review::factory()->create(['completed_at' => now()]);

    $this->get(route('today'))->assertDontSee('Revue prête', false);
});

test('the agent speaks in a single place: inbox is only nudged when no review is pending', function () {
    $this->actingAs(User::factory()->create());

    InboxItem::factory()->create(['processed_at' => null]);

    $this->get(route('today'))->assertSee('Inbox à trier');

    Review::factory()->create(['completed_at' => null]);

    $this->get(route('today'))
        ->assertSee('Revue prête', false)
        ->assertDontSee('Inbox à trier');
});

test('isolates the first open task as "la suivante" and leaves the others below', function () {
    $this->actingAs(User::factory()->create());

    $first = Task::factory()->create(['title' => 'Première tâche', 'due_date' => today()->subDay()]);
    $second = Task::factory()->create(['title' => 'Deuxième tâche', 'scheduled_date' => today()]);

    expect(Livewire::test('pages::today')->get('nextTask')->id)->toBe($first->id);

    $this->get(route('today'))
        ->assertSee('La suivante')
        ->assertSee($first->title)
        ->assertSee($second->title);
});

test('postponing "la suivante" moves it to tomorrow', function () {
    $this->actingAs(User::factory()->create());

    $task = Task::factory()->create(['scheduled_date' => today()]);

    Livewire::test('pages::today')->call('postponeTask', $task->id);

    expect($task->fresh()->scheduled_date->isTomorrow())->toBeTrue();
});

test('lists open tasks with no scheduled date and no due date under "En réserve"', function () {
    $this->actingAs(User::factory()->create());

    $unscheduled = Task::factory()->create(['title' => 'No date task', 'scheduled_date' => null, 'due_date' => null]);
    Task::factory()->create(['title' => 'Scheduled task', 'scheduled_date' => today()]);
    Task::factory()->create(['title' => 'Due later task', 'scheduled_date' => null, 'due_date' => today()->addWeek()]);
    Task::factory()->done()->create(['title' => 'Done no date task', 'scheduled_date' => null, 'due_date' => null]);

    $unscheduledTasks = Livewire::test('pages::today')->get('unscheduledTasks');

    expect($unscheduledTasks->pluck('id')->all())->toBe([$unscheduled->id]);

    $this->get(route('today'))
        ->assertSee('En réserve')
        ->assertSee($unscheduled->title);
});

test('does not show the "En réserve" section when every open task has a date', function () {
    $this->actingAs(User::factory()->create());

    Task::factory()->create(['title' => 'Scheduled task', 'scheduled_date' => today()]);

    $this->get(route('today'))->assertDontSee('En réserve');
});

test('scheduling an unscheduled task for today moves it into the day', function () {
    $this->actingAs(User::factory()->create());

    $task = Task::factory()->create(['title' => 'No date task', 'scheduled_date' => null, 'due_date' => null]);

    Livewire::test('pages::today')->call('scheduleForToday', $task->id);

    expect($task->fresh()->scheduled_date->isToday())->toBeTrue();
});

test('the reserve can be emptied onto today in one gesture', function () {
    $this->actingAs(User::factory()->create());

    $first = Task::factory()->create(['scheduled_date' => null, 'due_date' => null]);
    $second = Task::factory()->create(['scheduled_date' => null, 'due_date' => null]);

    Livewire::test('pages::today')->call('scheduleAllForToday');

    expect($first->fresh()->scheduled_date->isToday())->toBeTrue()
        ->and($second->fresh()->scheduled_date->isToday())->toBeTrue();
});

test('titles the day with the streak', function () {
    $this->actingAs(User::factory()->create());

    Task::factory()->done()->create(['completed_at' => now()]);
    Task::factory()->done()->create(['completed_at' => now()->subDay()]);

    expect(Livewire::test('pages::today')->get('dayTitle'))->toBe("Deuxième jour d'affilée");
});

test('celebrates a goal closed in the last two days', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['title' => 'Vendre l\'appartement', 'status' => 'done']);

    $this->get(route('today'))->assertSee('Objectif atteint');

    Livewire::test('pages::today')->call('archiveGoal', $goal->id);

    expect($goal->fresh()->status)->toBe(GoalStatus::Abandoned);
});
