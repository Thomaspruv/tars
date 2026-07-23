<?php

use App\Models\Goal;
use App\Models\LifeArea;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('goals.index'))->assertRedirect(route('login'));
});

test('it lists goals grouped by life area', function () {
    $this->actingAs(User::factory()->create());

    $area = LifeArea::factory()->create(['name' => 'Immobilier']);
    Goal::factory()->create(['life_area_id' => $area->id, 'title' => 'Renover SDB']);

    $this->get(route('goals.index'))
        ->assertSee('Immobilier')
        ->assertSee('Renover SDB');
});

test('it hides empty life areas', function () {
    $this->actingAs(User::factory()->create());

    LifeArea::factory()->create(['name' => 'Sans objectifs']);

    $this->get(route('goals.index'))->assertSee("Aucun objectif pour l'instant.", false);
});

test('it creates a goal', function () {
    $this->actingAs(User::factory()->create());

    $area = LifeArea::factory()->create();

    Livewire::test('pages::goals')
        ->call('openCreateModal')
        ->set('title', 'Semi-marathon')
        ->set('lifeAreaId', $area->id)
        ->call('createGoal')
        ->assertSet('showCreateModal', false);

    expect(Goal::where('title', 'Semi-marathon')->where('life_area_id', $area->id)->exists())->toBeTrue();
});

test('it requires a title and a life area to create a goal', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::goals')
        ->call('openCreateModal')
        ->set('title', '')
        ->call('createGoal')
        ->assertHasErrors(['title']);
});
