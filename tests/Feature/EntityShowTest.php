<?php

use App\Enums\EntityStatus;
use App\Enums\TaskStatus;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $entity = Entity::factory()->create();

    $this->get(route('entities.show', $entity))->assertRedirect(route('login'));
});

test('it shows the entity with open tasks and linked goals', function () {
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create(['name' => 'SARL Alpha']);
    $task = Task::factory()->create(['entity_id' => $entity->id, 'title' => 'Preparer TVA', 'status' => 'todo']);
    $doneTask = Task::factory()->done()->create(['entity_id' => $entity->id, 'title' => 'Ancienne tache']);
    $goal = Goal::factory()->create(['entity_id' => $entity->id, 'title' => '500k CA']);

    $response = $this->get(route('entities.show', $entity));

    $response->assertSee('SARL Alpha')
        ->assertSee('Preparer TVA')
        ->assertSee('500k CA')
        ->assertDontSee('Ancienne tache');
});

test('it toggles an open task from the entity page', function () {
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create();
    $task = Task::factory()->create(['entity_id' => $entity->id, 'status' => 'todo']);

    Livewire::test('pages::entities.show', ['entity' => $entity])
        ->call('toggleTask', $task->id);

    expect($task->fresh()->status)->toBe(TaskStatus::Done);
});

test('it edits the entity', function () {
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create(['name' => 'Old name']);

    Livewire::test('pages::entities.show', ['entity' => $entity])
        ->call('openEditModal')
        ->set('editName', 'New name')
        ->call('saveEdit');

    expect($entity->fresh()->name)->toBe('New name');
});

test('it archives the entity', function () {
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create(['status' => 'active']);

    Livewire::test('pages::entities.show', ['entity' => $entity])
        ->call('archive')
        ->assertRedirect(route('entities.index'));

    expect($entity->fresh()->status)->toBe(EntityStatus::Archived);
});

test('it adds a note to the entity', function () {
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create();

    Livewire::test('pages::entities.show', ['entity' => $entity])
        ->set('newNoteContent', 'Contrat signé le 12')
        ->call('addNote');

    expect($entity->notes()->where('content', 'Contrat signé le 12')->exists())->toBeTrue();
});
