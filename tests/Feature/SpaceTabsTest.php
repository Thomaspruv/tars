<?php

use App\Models\User;
use App\Support\Navigation\CurrentRoute;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(fn () => test()->actingAs(User::factory()->create()));

/**
 * Régression. `request()->routeIs()` ne répond juste qu'au chargement d'une
 * page : un rendu Livewire — un clic, une validation, un `wire:poll` — arrive
 * sur `livewire.update`, et la barre d'onglets, conditionnée à la route
 * courante, disparaissait purement et simplement de l'écran. Sur Missions, qui
 * se rafraîchit toutes les cinq secondes, on perdait la navigation au bout de
 * cinq secondes.
 */
test('the tabs survive a livewire re-render, on every screen that has them', function (string $component, string $sibling) {
    expect(Livewire::test($component)->html())->toContain($sibling);
})->with([
    'missions' => ['pages::missions', 'Skills'],
    'agents' => ['pages::agents', 'Missions'],
    'tâches' => ['pages::tasks', 'Mois'],
    'objectifs' => ['pages::goals', 'Décisions'],
    'entités' => ['pages::entities', 'Listes'],
]);

test('a page that polls keeps its tabs after refreshing itself', function () {
    // Le cas vécu : l'écran Missions se rafraîchit tout seul, et c'est ce
    // rafraîchissement qui emportait la navigation.
    Livewire::test('pages::missions')
        ->call('$refresh')
        ->assertSee('Équipe')
        ->assertSee('Skills')
        ->assertSee('Système');
});

test('the current route is read from the livewire snapshot when there is no page route', function () {
    // Une vraie requête Livewire : la route courante est `livewire.update`, et
    // le chemin de la page ne vit plus que dans l'instantané du composant.
    $snapshot = json_encode(['memo' => ['path' => 'missions', 'method' => 'GET']]);

    $resolved = null;

    Route::post('/probe-livewire-origin', function () use (&$resolved) {
        $resolved = CurrentRoute::name();

        return 'ok';
    })->middleware('web')->name('livewire.update');

    $this->withHeaders(['X-Livewire' => '1'])
        ->post('/probe-livewire-origin', ['components' => [['snapshot' => $snapshot]]]);

    expect($resolved)->toBe('missions.index');
});

test('on a plain page load the current route is simply the matched one', function () {
    $this->get(route('skills.index'));

    // Et les onglets de l'espace sont rendus, celui qu'on regarde marqué.
    $this->get(route('skills.index'))->assertSeeInOrder(['Missions', 'Équipe', 'Skills', 'Système']);
});
