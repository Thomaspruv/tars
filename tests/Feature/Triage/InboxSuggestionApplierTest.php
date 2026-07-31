<?php

use App\Enums\InboxSuggestionStatus;
use App\Models\Entity;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Note;
use App\Models\Task;
use App\Support\Triage\InboxSuggestionApplier;

test('apply creates a task, resolves the goal by name, marks the inbox item processed', function () {
    $goal = Goal::factory()->create(['title' => 'Rénover la salle de bain']);
    $item = InboxItem::factory()->create();
    $suggestion = InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'suggested_type' => 'task',
        'suggested_fields' => ['title' => 'Rappeler le plombier', 'goal' => 'Rénover la salle de bain'],
    ]);

    app(InboxSuggestionApplier::class)->apply($suggestion);

    $task = Task::where('title', 'Rappeler le plombier')->firstOrFail();
    expect($task->goal_id)->toBe($goal->id)
        ->and($task->status->value)->toBe('todo');

    $suggestion->refresh();
    expect($suggestion->status)->toBe(InboxSuggestionStatus::Accepted)
        ->and($suggestion->created_type)->toBe(Task::class)
        ->and($suggestion->created_id)->toBe($task->id);

    $item->refresh();
    expect($item->processed_at)->not->toBeNull()
        ->and($item->converted_to_id)->toBe($task->id);
});

test('apply creates an event, resolves the entity by name', function () {
    $entity = Entity::factory()->create(['name' => 'Appart Lilas']);
    $item = InboxItem::factory()->create();
    $suggestion = InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'suggested_type' => 'event',
        'suggested_fields' => ['title' => 'RDV syndic', 'starts_at' => '2026-08-05 10:00', 'entity' => 'Appart Lilas'],
    ]);

    app(InboxSuggestionApplier::class)->apply($suggestion);

    $event = Event::where('title', 'RDV syndic')->firstOrFail();
    expect($event->entity_id)->toBe($entity->id);
});

test('apply creates a goal', function () {
    $item = InboxItem::factory()->create();
    $suggestion = InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'suggested_type' => 'goal',
        'suggested_fields' => ['title' => 'Courir un semi'],
    ]);

    app(InboxSuggestionApplier::class)->apply($suggestion);

    expect(Goal::where('title', 'Courir un semi')->exists())->toBeTrue();
});

test('apply creates a note', function () {
    $item = InboxItem::factory()->create();
    $suggestion = InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'suggested_type' => 'note',
        'suggested_fields' => ['content' => 'Le compteur est dans la cave.'],
    ]);

    app(InboxSuggestionApplier::class)->apply($suggestion);

    expect(Note::where('content', 'Le compteur est dans la cave.')->exists())->toBeTrue();
});

test('revert deletes the created record and restores the inbox item to pending', function () {
    $item = InboxItem::factory()->create();
    $suggestion = InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'suggested_type' => 'task',
        'suggested_fields' => ['title' => 'Rappeler le plombier'],
    ]);

    app(InboxSuggestionApplier::class)->apply($suggestion);
    $taskId = $suggestion->fresh()->created_id;

    app(InboxSuggestionApplier::class)->revert($suggestion->fresh());

    expect(Task::find($taskId))->toBeNull();

    $item->refresh();
    expect($item->processed_at)->toBeNull()
        ->and($item->converted_to_type)->toBeNull()
        ->and($item->converted_to_id)->toBeNull();

    expect($suggestion->fresh()->status)->toBe(InboxSuggestionStatus::Rejected);
});
