<?php

use App\Models\User;
use App\Support\Archiving\ArchiveSettings;
use Livewire\Livewire;

// Rendering pages::settings-app evaluates brainStatus(), which shells out to a
// real `git fetch` against BrainSettings::localPath(). Point it at a path with
// no .git so GitRepository::status() short-circuits to Unknown instead of
// hitting the developer's real local vault clone over the network.
beforeEach(fn () => config(['brain.local_path' => sys_get_temp_dir().'/settings-test-'.uniqid()]));

test('saves the archive after-days setting', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('archiveAfterDays', 14)
        ->call('saveArchiveSettings')
        ->assertHasNoErrors();

    expect((new ArchiveSettings)->afterDays())->toBe(14);
});

test('clearing the archive after-days setting disables archiving', function () {
    $this->actingAs(User::factory()->create());
    (new ArchiveSettings)->updateAfterDays(14);

    Livewire::test('pages::settings-app')
        ->set('archiveAfterDays', null)
        ->call('saveArchiveSettings')
        ->assertHasNoErrors();

    expect((new ArchiveSettings)->afterDays())->toBeNull();
});

test('rejects an invalid archive after-days value', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('archiveAfterDays', 0)
        ->call('saveArchiveSettings')
        ->assertHasErrors(['archiveAfterDays']);
});

test('confirms the archive settings save with an inline message', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('archiveAfterDays', 14)
        ->call('saveArchiveSettings')
        ->assertSee('✓ Enregistré');
});
