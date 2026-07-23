<?php

use App\Enums\InboxConvertedType;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\LifeArea;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('inbox.index'))->assertRedirect(route('login'));
});

test('it lists unprocessed inbox items and shows an empty state when there are none', function () {
    $this->actingAs(User::factory()->create());

    $pending = InboxItem::factory()->create(['content' => 'Acheter du lait']);
    $processed = InboxItem::factory()->create(['content' => 'Deja trie', 'processed_at' => now()]);

    $this->get(route('inbox.index'))
        ->assertSee('Acheter du lait')
        ->assertDontSee('Deja trie');

    $processed->delete();
    $pending->delete();

    $this->get(route('inbox.index'))->assertSee('Inbox à zéro');
});

test('it converts an inbox item to a task', function () {
    $this->actingAs(User::factory()->create());

    $item = InboxItem::factory()->create(['content' => 'Relancer le plombier']);

    Livewire::test('pages::inbox')->call('convertToTask', $item->id);

    expect(Task::where('title', 'Relancer le plombier')->exists())->toBeTrue();

    $item->refresh();
    expect($item->processed_at)->not->toBeNull();
    expect($item->converted_to_type)->toBe(InboxConvertedType::Task);
});

test('it converts an inbox item to an event', function () {
    $this->actingAs(User::factory()->create());

    $item = InboxItem::factory()->create(['content' => 'Diner jeudi']);

    Livewire::test('pages::inbox')->call('convertToEvent', $item->id);

    expect(Event::where('title', 'Diner jeudi')->exists())->toBeTrue();
    expect($item->fresh()->processed_at)->not->toBeNull();
});

test('it converts an inbox item to a goal within a chosen life area', function () {
    $this->actingAs(User::factory()->create());

    $lifeArea = LifeArea::factory()->create();
    $item = InboxItem::factory()->create(['content' => 'Courir un semi']);

    Livewire::test('pages::inbox')
        ->call('startConvertToGoal', $item->id)
        ->set('selectedLifeAreaId', $lifeArea->id)
        ->call('confirmConvertToGoal', $item->id);

    $goal = Goal::where('title', 'Courir un semi')->first();

    expect($goal)->not->toBeNull();
    expect($goal->life_area_id)->toBe($lifeArea->id);
    expect($item->fresh()->processed_at)->not->toBeNull();
});

test('it discards an inbox item', function () {
    $this->actingAs(User::factory()->create());

    $item = InboxItem::factory()->create();

    Livewire::test('pages::inbox')->call('discard', $item->id);

    expect(InboxItem::find($item->id))->toBeNull();
});
