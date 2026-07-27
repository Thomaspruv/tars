<?php

use App\Enums\EntityRelationType;
use App\Enums\EntityStatus;
use App\Enums\TaskStatus;
use App\Models\BrainDocument;
use App\Models\Entity;
use App\Models\EntityRelation;
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

test('it edits the type-specific detail fields', function () {
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create(['type' => 'vehicle', 'details' => ['plate_number' => 'AA-000-BB']]);

    Livewire::test('pages::entities.show', ['entity' => $entity])
        ->call('openEditModal')
        ->assertSet('editDetails.plate_number', 'AA-000-BB')
        ->set('editDetails.plate_number', 'CC-111-DD')
        ->set('editDetails.make_model', 'Peugeot 208')
        ->call('saveEdit');

    expect($entity->fresh()->details)->toBe([
        'plate_number' => 'CC-111-DD',
        'make_model' => 'Peugeot 208',
    ]);
});

test('it shows the filled detail fields on the fiche', function () {
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create([
        'type' => 'property',
        'details' => ['address' => '3 rue de la République', 'occupation' => 'rented'],
    ]);

    $this->get(route('entities.show', $entity))
        ->assertSee('Informations')
        ->assertSee('3 rue de la République')
        ->assertSee('Loué');
});

test('the informations section is hidden when there are no detail fields', function () {
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create(['details' => []]);

    $this->get(route('entities.show', $entity))->assertDontSee('Informations');
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

test('it shows anchored vault documents when the vault is configured', function () {
    config(['brain.remote_url' => 'git@example.com:vault.git']);
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create();
    $document = BrainDocument::factory()->create(['title' => 'Réunion SARL Alpha']);
    $entity->brainDocuments()->attach($document);

    $this->get(route('entities.show', $entity))->assertSee('Réunion SARL Alpha');
});

test('the vault notes section is hidden when the vault is not configured', function () {
    config(['brain.remote_url' => null]);
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create();

    $this->get(route('entities.show', $entity))->assertDontSee('Notes du vault');
});

test('it shows outgoing and incoming entity relations', function () {
    $this->actingAs(User::factory()->create());

    $person = Entity::factory()->create(['name' => 'Jean Dupont']);
    $company = Entity::factory()->create(['name' => 'Acme Corp']);

    EntityRelation::create([
        'entity_id' => $person->id,
        'related_entity_id' => $company->id,
        'relation_type' => EntityRelationType::EmployedBy,
    ]);

    $this->get(route('entities.show', $person))->assertSee('Acme Corp');
    $this->get(route('entities.show', $company))->assertSee('Jean Dupont')->assertSee('entrant');
});
