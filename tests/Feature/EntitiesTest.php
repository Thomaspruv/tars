<?php

use App\Models\Entity;
use App\Models\LifeArea;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('entities.index'))->assertRedirect(route('login'));
});

test('it lists active entities and hides archived ones', function () {
    $this->actingAs(User::factory()->create());

    $active = Entity::factory()->create(['name' => 'SARL Alpha', 'status' => 'active']);
    $archived = Entity::factory()->create(['name' => 'Ancienne SARL', 'status' => 'archived']);

    $this->get(route('entities.index'))
        ->assertSee('SARL Alpha')
        ->assertDontSee('Ancienne SARL');
});

test('it creates an entity', function () {
    $this->actingAs(User::factory()->create());

    $area = LifeArea::factory()->create();

    Livewire::test('pages::entities')
        ->call('openCreateModal')
        ->set('name', 'Appart Lyon')
        ->set('type', 'property')
        ->set('lifeAreaId', $area->id)
        ->call('createEntity')
        ->assertSet('showCreateModal', false);

    expect(Entity::where('name', 'Appart Lyon')->where('life_area_id', $area->id)->exists())->toBeTrue();
});

test('it requires a name to create an entity', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::entities')
        ->call('openCreateModal')
        ->set('name', '')
        ->call('createEntity')
        ->assertHasErrors(['name']);
});

test('it creates an entity with type-specific detail fields', function () {
    $this->actingAs(User::factory()->create());

    $area = LifeArea::factory()->create();

    Livewire::test('pages::entities')
        ->call('openCreateModal')
        ->set('name', 'Appart Lyon')
        ->set('type', 'property')
        ->set('lifeAreaId', $area->id)
        ->set('details.address', '3 rue de la République')
        ->set('details.surface_m2', '54')
        ->set('details.occupation', '')
        ->call('createEntity')
        ->assertSet('showCreateModal', false);

    $entity = Entity::where('name', 'Appart Lyon')->firstOrFail();

    expect($entity->details)->toBe([
        'address' => '3 rue de la République',
        'surface_m2' => '54',
    ]);
});

test('detail fields are all optional', function () {
    $this->actingAs(User::factory()->create());

    $area = LifeArea::factory()->create();

    Livewire::test('pages::entities')
        ->call('openCreateModal')
        ->set('name', 'SCI Test')
        ->set('type', 'company')
        ->set('lifeAreaId', $area->id)
        ->call('createEntity')
        ->assertHasNoErrors();

    expect(Entity::where('name', 'SCI Test')->firstOrFail()->details)->toBe([]);
});

test('switching type clears the previously entered detail fields', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::entities')
        ->call('openCreateModal')
        ->set('type', 'property')
        ->set('details.address', '3 rue de la République')
        ->set('type', 'vehicle')
        ->assertSet('details', []);
});
