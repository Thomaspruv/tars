<?php

use App\Models\InboxItem;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('share.target'))->assertRedirect(route('login'));
});

test('it prefills the note with what the sharing app sent', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('share.target', [
        'title' => 'Un article de fond',
        'text' => 'Un extrait qui vaut le coup',
        'url' => 'https://example.com/article',
    ]))
        ->assertOk()
        ->assertSee("Un article de fond\nUn extrait qui vaut le coup\nhttps://example.com/article");
});

test('it does not repeat the title when the shared text already contains it', function () {
    Livewire::withQueryParams([
        'title' => 'Un article de fond',
        'text' => 'Un article de fond — le chapo',
        'url' => 'https://example.com/article',
    ])
        ->actingAs(User::factory()->create())
        ->test('pages::share')
        ->assertSet('content', "Un article de fond — le chapo\nhttps://example.com/article");
});

test('it does not repeat the url when the shared text already carries it', function () {
    Livewire::withQueryParams([
        'text' => 'A lire : https://example.com/article',
        'url' => 'https://example.com/article',
    ])
        ->actingAs(User::factory()->create())
        ->test('pages::share')
        ->assertSet('content', 'A lire : https://example.com/article');
});

test('it opens on an empty note when nothing was shared', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::share')
        ->assertSet('content', '');
});

test('it captures the shared note into the inbox', function () {
    Livewire::withQueryParams(['url' => 'https://example.com/article'])
        ->actingAs(User::factory()->create())
        ->test('pages::share')
        ->call('capture')
        ->assertSet('captured', true);

    expect(InboxItem::where('content', 'https://example.com/article')->exists())->toBeTrue();
});

test('it refuses to capture an empty note', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::share')
        ->set('content', '   ')
        ->call('capture')
        ->assertHasErrors(['content'])
        ->assertSet('captured', false);

    expect(InboxItem::count())->toBe(0);
});

test('it can capture another note right after one was posted', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::share')
        ->set('content', 'Premiere note')
        ->call('capture')
        ->assertSet('captured', true)
        ->call('captureAnother')
        ->assertSet('captured', false)
        ->assertSet('content', '');
});
