<?php

use App\Enums\TaskStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

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
    $unpinned = Checklist::factory()->create(['name' => 'Not pinned', 'is_pinned' => false]);
    $item = ChecklistItem::factory()->create(['list_id' => $pinned->id, 'content' => 'Lait']);

    $this->get(route('today'))
        ->assertSee('Courses')
        ->assertSee('Lait')
        ->assertDontSee('Not pinned');

    Livewire::test('pages::today')->call('toggleListItem', $item->id);

    expect($item->fresh()->checked_at)->not->toBeNull();
});
