<?php

use App\Models\InboxItem;
use App\Models\User;

/**
 * @return array<string, mixed>
 */
function manifest(): array
{
    $decoded = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

test('the manifest is valid and installable', function () {
    $manifest = manifest();

    expect($manifest['name'])->toBe('Tars')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toBe('/');
});

test('every icon the manifest declares actually exists', function () {
    $manifest = manifest();

    expect($manifest['icons'])->not->toBeEmpty();

    foreach ($manifest['icons'] as $icon) {
        expect(file_exists(public_path(ltrim($icon['src'], '/'))))->toBeTrue($icon['src'].' est déclarée mais absente');
    }

    // Android exige une icône maskable, sinon il rogne le logo dans un cercle.
    expect(collect($manifest['icons'])->pluck('purpose'))->toContain('maskable');
});

test('the share target points at a route that exists', function () {
    $manifest = manifest();

    expect($manifest['share_target']['method'])->toBe('GET')
        ->and($manifest['share_target']['action'])->toBe(route('share.target', absolute: false))
        ->and($manifest['share_target']['params'])->toBe(['title' => 'title', 'text' => 'text', 'url' => 'url']);
});

test('the shortcuts point at routes that exist', function () {
    foreach (manifest()['shortcuts'] as $shortcut) {
        expect(collect([route('share.target', absolute: false), route('inbox.index', absolute: false)]))
            ->toContain($shortcut['url']);
    }
});

test('the service worker precaches the offline page it falls back to', function () {
    $worker = (string) file_get_contents(public_path('sw.js'));

    expect($worker)->toContain("const OFFLINE_URL = '/hors-ligne.html'")
        ->and(file_exists(public_path('hors-ligne.html')))->toBeTrue();

    // Le worker ne doit jamais intercepter autre chose qu'une navigation :
    // les échanges Livewire passent en POST et doivent filer au réseau.
    expect($worker)->toContain("request.mode !== 'navigate'");
});

test('the layout advertises the manifest and follows the app theme', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('today'))
        ->assertSee('rel="manifest" href="/manifest.webmanifest"', false)
        ->assertSee('name="theme-color" content="#0F1310"', false);

    $this->withUnencryptedCookie('la-theme', 'light')
        ->get(route('today'))
        ->assertSee('name="theme-color" content="#F3F5EE"', false);
});

test('guests cannot flush a capture queue', function () {
    $this->post(route('inbox.queue.flush'), ['items' => [['content' => 'Note volee']]])
        ->assertRedirect(route('login'));

    expect(InboxItem::count())->toBe(0);
});

test('it stores notes captured while the device was offline', function () {
    $this->actingAs(User::factory()->create());

    $this->postJson(route('inbox.queue.flush'), ['items' => [
        ['content' => 'Pensee du tunnel'],
        ['content' => '  Relancer le syndic  '],
    ]])
        ->assertOk()
        ->assertJson(['stored' => 2]);

    expect(InboxItem::pluck('content')->all())->toBe(['Pensee du tunnel', 'Relancer le syndic']);
});

test('it rejects an empty or oversized queue', function () {
    $this->actingAs(User::factory()->create());

    $this->postJson(route('inbox.queue.flush'), ['items' => []])->assertJsonValidationErrorFor('items');
    $this->postJson(route('inbox.queue.flush'), ['items' => [['content' => '']]])->assertJsonValidationErrorFor('items.0.content');
    $this->postJson(route('inbox.queue.flush'), ['items' => [['content' => str_repeat('a', 5001)]]])->assertJsonValidationErrorFor('items.0.content');

    expect(InboxItem::count())->toBe(0);
});
