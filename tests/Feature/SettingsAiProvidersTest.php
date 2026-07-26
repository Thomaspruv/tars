<?php

use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// Rendering pages::settings-app evaluates brainStatus(), which shells out to a
// real `git fetch` against BrainSettings::localPath(). Point it at a path with
// no .git so GitRepository::status() short-circuits to Unknown instead of
// hitting the developer's real local vault clone over the network.
beforeEach(fn () => config(['brain.local_path' => sys_get_temp_dir().'/settings-test-'.uniqid()]));

test('creates a provider', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->call('openCreateProviderModal')
        ->set('providerName', 'anthropic')
        ->set('providerApiKey', 'sk-ant-secret')
        ->set('providerBaseUrl', '')
        ->call('saveProvider')
        ->assertSet('showProviderModal', false);

    $provider = AiProvider::where('name', 'anthropic')->firstOrFail();
    expect($provider->api_key)->toBe('sk-ant-secret')
        ->and($provider->is_active)->toBeTrue();
});

test('requires an api key to create a provider', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->call('openCreateProviderModal')
        ->set('providerName', 'anthropic')
        ->set('providerApiKey', '')
        ->call('saveProvider')
        ->assertHasErrors(['providerApiKey']);
});

test('editing a provider without touching the key preserves it', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create(['name' => 'Old name', 'api_key' => 'original-key']);

    Livewire::test('pages::settings-app')
        ->call('openEditProviderModal', $provider->id)
        ->assertSet('providerApiKey', '')
        ->set('providerName', 'New name')
        ->call('saveProvider');

    $provider->refresh();
    expect($provider->name)->toBe('New name')
        ->and($provider->api_key)->toBe('original-key');
});

test('editing a provider can replace the key', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create(['api_key' => 'original-key']);

    Livewire::test('pages::settings-app')
        ->call('openEditProviderModal', $provider->id)
        ->set('providerApiKey', 'new-key')
        ->call('saveProvider');

    expect($provider->fresh()->api_key)->toBe('new-key');
});

test('deletes a provider', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();

    Livewire::test('pages::settings-app')->call('deleteProvider', $provider->id);

    expect(AiProvider::find($provider->id))->toBeNull();
});

test('tests the connection successfully', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*/v1/models' => Http::response(['data' => []], 200)]);

    $provider = AiProvider::factory()->create(['name' => 'anthropic']);

    Livewire::test('pages::settings-app')
        ->call('testProviderConnection', $provider->id)
        ->assertSee('Connexion réussie.');
});

test('reports a failed connection test', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*/models' => Http::response(null, 401)]);

    $provider = AiProvider::factory()->create(['name' => 'openai']);

    Livewire::test('pages::settings-app')
        ->call('testProviderConnection', $provider->id)
        ->assertSee('Échec de la connexion.');
});
