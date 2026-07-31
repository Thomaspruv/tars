<?php

use App\Enums\InboxConvertedType;
use App\Enums\InboxSuggestionStatus;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Task;
use App\Models\User;
use App\Support\Triage\InboxSuggestionApplier;
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

test('it converts an inbox item to a goal', function () {
    $this->actingAs(User::factory()->create());

    $item = InboxItem::factory()->create(['content' => 'Courir un semi']);

    Livewire::test('pages::inbox')->call('convertToGoal', $item->id);

    $goal = Goal::where('title', 'Courir un semi')->first();

    expect($goal)->not->toBeNull();
    expect($item->fresh()->processed_at)->not->toBeNull();
});

test('it discards an inbox item', function () {
    $this->actingAs(User::factory()->create());

    $item = InboxItem::factory()->create();

    Livewire::test('pages::inbox')->call('discard', $item->id);

    expect(InboxItem::find($item->id))->toBeNull();
});

test('it shows a pending triage suggestion and accepts it', function () {
    $this->actingAs(User::factory()->create());

    $item = InboxItem::factory()->create(['content' => 'Rappeler le plombier']);
    $suggestion = InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'suggested_type' => 'task',
        'suggested_fields' => ['title' => 'Rappeler le plombier'],
        'reason' => 'Action évidente.',
    ]);

    Livewire::test('pages::inbox')
        ->assertSee('Action évidente.')
        ->call('acceptSuggestion', $suggestion->id);

    expect(Task::where('title', 'Rappeler le plombier')->exists())->toBeTrue()
        ->and($item->fresh()->processed_at)->not->toBeNull()
        ->and($suggestion->fresh()->status)->toBe(InboxSuggestionStatus::Accepted);
});

test('it rejects a pending triage suggestion without touching the inbox item', function () {
    $this->actingAs(User::factory()->create());

    $item = InboxItem::factory()->create();
    $suggestion = InboxSuggestion::factory()->create(['inbox_item_id' => $item->id]);

    Livewire::test('pages::inbox')->call('rejectSuggestion', $suggestion->id);

    expect($suggestion->fresh()->status)->toBe(InboxSuggestionStatus::Rejected)
        ->and($item->fresh()->processed_at)->toBeNull();
});

test('it cancels an auto-applied suggestion and restores the item to pending', function () {
    $this->actingAs(User::factory()->create());

    $item = InboxItem::factory()->create();
    $suggestion = InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'suggested_type' => 'task',
        'suggested_fields' => ['title' => 'Rappeler le plombier'],
    ]);

    app(InboxSuggestionApplier::class)->apply($suggestion);
    $suggestion->update(['status' => InboxSuggestionStatus::AutoApplied]);
    $taskId = $suggestion->fresh()->created_id;

    Livewire::test('pages::inbox')->call('cancelAutoApplied', $suggestion->id);

    expect(Task::find($taskId))->toBeNull()
        ->and($item->fresh()->processed_at)->toBeNull();
});
