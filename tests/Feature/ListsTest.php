<?php

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('lists.index'))->assertRedirect(route('login'));
});

test('it lists checklists with their items, pinned first', function () {
    $this->actingAs(User::factory()->create());

    $courses = Checklist::factory()->create(['name' => 'Courses', 'is_pinned' => true]);
    ChecklistItem::factory()->create(['list_id' => $courses->id, 'content' => 'Lait']);

    $this->get(route('lists.index'))
        ->assertSee('Courses')
        ->assertSee('Lait');
});

test('it creates a list', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::lists')
        ->call('openCreateModal')
        ->set('name', 'Travaux SDB')
        ->call('createList')
        ->assertSet('showCreateModal', false);

    expect(Checklist::where('name', 'Travaux SDB')->exists())->toBeTrue();
});

test('it toggles pin on a list', function () {
    $this->actingAs(User::factory()->create());

    $list = Checklist::factory()->create(['is_pinned' => false]);

    Livewire::test('pages::lists')->call('togglePin', $list->id);

    expect($list->fresh()->is_pinned)->toBeTrue();
});

test('it adds and toggles an item', function () {
    $this->actingAs(User::factory()->create());

    $list = Checklist::factory()->create();

    Livewire::test('pages::lists')
        ->set("newItemContent.{$list->id}", 'Pain')
        ->call('addItem', $list->id);

    $item = $list->items()->where('content', 'Pain')->first();
    expect($item)->not->toBeNull();

    Livewire::test('pages::lists')->call('toggleItem', $item->id);

    expect($item->fresh()->checked_at)->not->toBeNull();
});

test('it deletes a list and an item', function () {
    $this->actingAs(User::factory()->create());

    $list = Checklist::factory()->create();
    $item = ChecklistItem::factory()->create(['list_id' => $list->id]);

    Livewire::test('pages::lists')->call('deleteItem', $item->id);
    expect(ChecklistItem::find($item->id))->toBeNull();

    Livewire::test('pages::lists')->call('deleteList', $list->id);
    expect(Checklist::find($list->id))->toBeNull();
});
