<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('a task exposes its subtasks and parent task', function () {
    $parent = Task::factory()->create(['title' => 'Parent']);
    $subtask = Task::factory()->subtaskOf($parent)->create(['title' => 'Enfant']);

    expect($parent->fresh()->subtasks)->toHaveCount(1);
    expect($parent->fresh()->subtasks->first()->id)->toBe($subtask->id);
    expect($subtask->fresh()->parentTask->id)->toBe($parent->id);
});

test('deleting a parent task deletes its subtasks', function () {
    $parent = Task::factory()->create();
    $subtask = Task::factory()->subtaskOf($parent)->create();

    $parent->delete();

    expect(Task::find($subtask->id))->toBeNull();
});

test('the topLevel scope excludes subtasks', function () {
    $parent = Task::factory()->create();
    Task::factory()->subtaskOf($parent)->create();

    $topLevel = Task::topLevel()->get();

    expect($topLevel)->toHaveCount(1);
    expect($topLevel->first()->id)->toBe($parent->id);
});

test('subtasks are excluded from the today screen top-level query', function () {
    $this->actingAs(User::factory()->create());

    $parent = Task::factory()->create(['title' => 'Tache parente', 'scheduled_date' => today()]);
    Task::factory()->subtaskOf($parent)->create(['title' => 'Sous-tache', 'scheduled_date' => today()]);

    $component = Livewire::test('pages::today');

    expect($component->get('tasksToday'))->toHaveCount(1);
    $component->assertSee('0/1');
});

test('subtasks are excluded from the tasks screen top-level query and orphan ratio', function () {
    $this->actingAs(User::factory()->create());

    $parent = Task::factory()->create(['title' => 'Tache parente', 'goal_id' => null]);
    Task::factory()->subtaskOf($parent)->create(['title' => 'Sous-tache', 'goal_id' => null]);

    $component = Livewire::test('pages::tasks');

    expect($component->get('tasks'))->toHaveCount(1);
    $component->assertSee('1/1')->assertSee('100%');
});

test('creating a subtask from the tasks screen attaches it to its parent', function () {
    $this->actingAs(User::factory()->create());

    $parent = Task::factory()->create(['title' => 'Tache parente']);

    Livewire::test('pages::tasks')->call('createSubtask', $parent->id, 'Nouvelle sous-tache');

    expect($parent->fresh()->subtasks)->toHaveCount(1);
    expect($parent->fresh()->subtasks->first()->title)->toBe('Nouvelle sous-tache');
});

test('creating a subtask with a blank title is ignored', function () {
    $this->actingAs(User::factory()->create());

    $parent = Task::factory()->create();

    Livewire::test('pages::tasks')->call('createSubtask', $parent->id, '   ');

    expect($parent->fresh()->subtasks)->toHaveCount(0);
});

test('creating a subtask from the today screen attaches it to its parent', function () {
    $this->actingAs(User::factory()->create());

    $parent = Task::factory()->create(['scheduled_date' => today()]);

    Livewire::test('pages::today')->call('createSubtask', $parent->id, 'Sous-tache du jour');

    expect($parent->fresh()->subtasks)->toHaveCount(1);
});

test('toggling a subtask does not affect its parent status', function () {
    $this->actingAs(User::factory()->create());

    $parent = Task::factory()->create(['scheduled_date' => today()]);
    $subtask = Task::factory()->subtaskOf($parent)->create(['status' => 'todo']);

    Livewire::test('pages::today')->call('toggleTask', $subtask->id);

    expect($subtask->fresh()->status)->toBe(TaskStatus::Done);
    expect($parent->fresh()->status)->toBe(TaskStatus::Todo);
});
