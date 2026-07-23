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
        ->set('context', 'perso')
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
