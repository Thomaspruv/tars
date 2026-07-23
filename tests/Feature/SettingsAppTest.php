<?php

use App\Models\LifeArea;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('settings.index'))->assertRedirect(route('login'));
});

test('it lists life areas', function () {
    $this->actingAs(User::factory()->create());

    LifeArea::factory()->create(['name' => 'Santé']);

    $this->get(route('settings.index'))->assertSee('Santé');
});

test('it creates a life area', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->call('openCreateModal')
        ->set('name', 'Finances')
        ->set('color', '#4ADE80')
        ->call('save')
        ->assertSet('showFormModal', false);

    expect(LifeArea::where('name', 'Finances')->where('color', '#4ADE80')->exists())->toBeTrue();
});

test('it requires a name to create a life area', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->call('openCreateModal')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name']);
});

test('it edits a life area', function () {
    $this->actingAs(User::factory()->create());

    $lifeArea = LifeArea::factory()->create(['name' => 'Old name']);

    Livewire::test('pages::settings-app')
        ->call('openEditModal', $lifeArea->id)
        ->set('name', 'New name')
        ->call('save');

    expect($lifeArea->fresh()->name)->toBe('New name');
});

test('it deletes a life area', function () {
    $this->actingAs(User::factory()->create());

    $lifeArea = LifeArea::factory()->create();

    Livewire::test('pages::settings-app')->call('delete', $lifeArea->id);

    expect(LifeArea::find($lifeArea->id))->toBeNull();
});
