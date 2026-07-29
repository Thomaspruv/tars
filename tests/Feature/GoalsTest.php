<?php

use App\Models\Goal;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('goals.index'))->assertRedirect(route('login'));
});

test('it lists goals', function () {
    $this->actingAs(User::factory()->create());

    Goal::factory()->create(['title' => 'Renover SDB']);

    $this->get(route('goals.index'))->assertSee('Renover SDB');
});

test('it shows an empty state when there are no goals', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('goals.index'))->assertSee("Aucun objectif pour l'instant.", false);
});

test('it creates a goal', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::goals')
        ->call('openCreateModal')
        ->set('title', 'Semi-marathon')
        ->call('createGoal')
        ->assertSet('showCreateModal', false);

    expect(Goal::where('title', 'Semi-marathon')->exists())->toBeTrue();
});

test('it requires a title to create a goal', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::goals')
        ->call('openCreateModal')
        ->set('title', '')
        ->call('createGoal')
        ->assertHasErrors(['title']);
});
