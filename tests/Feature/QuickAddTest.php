<?php

use App\Enums\TaskPriority;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('it creates a task when the input has recognizable syntax', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['title' => '500k CA SARL Alpha']);
    $entity = Entity::factory()->create(['name' => 'Appart Lilas']);

    Livewire::test('quick-add')
        ->set('query', 'préparer bilan demain #500k-ca-sarl-alpha p1 @lilas')
        ->call('submit')
        ->assertSet('query', '');

    $task = Task::where('title', 'préparer bilan')->first();

    expect($task)->not->toBeNull();
    expect($task->priority)->toBe(TaskPriority::P1);
    expect($task->goal_id)->toBe($goal->id);
    expect($task->entity_id)->toBe($entity->id);
    expect($task->scheduled_date->toDateString())->toBe(today()->addDay()->toDateString());
});

test('it falls back to the inbox when nothing is recognized', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('quick-add')
        ->set('query', 'Acheter du lait')
        ->call('submit');

    expect(InboxItem::where('content', 'Acheter du lait')->exists())->toBeTrue();
    expect(Task::count())->toBe(0);
});

test('it does nothing for an empty submission', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('quick-add')
        ->set('query', '   ')
        ->call('submit');

    expect(InboxItem::count())->toBe(0);
    expect(Task::count())->toBe(0);
});

test('it exposes the parsed preview as a computed property', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('quick-add')
        ->set('query', 'Appeler demain p2')
        ->assertSee('demain')
        ->assertSee('p2')
        ->assertSee('↵ tâche');
});
