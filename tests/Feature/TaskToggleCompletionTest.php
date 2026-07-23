<?php

use App\Enums\TaskStatus;
use App\Models\Task;

test('it marks a non-recurring task done', function () {
    $task = Task::factory()->create(['status' => 'todo', 'recurrence' => null]);

    $task->toggleCompletion();

    expect($task->status)->toBe(TaskStatus::Done);
    expect($task->completed_at)->not->toBeNull();
});

test('it marks a done non-recurring task back to todo', function () {
    $task = Task::factory()->done()->create(['recurrence' => null]);

    $task->toggleCompletion();

    expect($task->status)->toBe(TaskStatus::Todo);
    expect($task->completed_at)->toBeNull();
});

test('completing a recurring task rolls its due date forward instead of marking it done', function () {
    $task = Task::factory()->create([
        'status' => 'todo',
        'recurrence' => 'monthly:15',
        'due_date' => today()->startOfMonth()->addDays(14),
    ]);

    $originalDueDate = $task->due_date->copy();

    $task->toggleCompletion();

    expect($task->status)->toBe(TaskStatus::Todo);
    expect($task->completed_at)->toBeNull();
    expect($task->due_date->toDateString())->not->toBe($originalDueDate->toDateString());
    expect($task->due_date->greaterThan($originalDueDate))->toBeTrue();
});

test('completing a recurring task only rolls forward the date column that was set', function () {
    $task = Task::factory()->create([
        'status' => 'todo',
        'recurrence' => 'weekly:mon',
        'due_date' => null,
        'scheduled_date' => today(),
    ]);

    $task->toggleCompletion();

    expect($task->due_date)->toBeNull();
    expect($task->scheduled_date)->not->toBeNull();
});
