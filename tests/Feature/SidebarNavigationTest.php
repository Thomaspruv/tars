<?php

use App\Enums\BrainSuggestionStatus;
use App\Enums\OpenDecisionStatus;
use App\Models\BrainSuggestion;
use App\Models\InboxItem;
use App\Models\Mission;
use App\Models\OpenDecision;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(fn () => test()->actingAs(User::factory()->create()));

/**
 * La barre latérale seule : le reste de la page (la feuille « Plus » du
 * mobile, le contenu de l'écran) nomme forcément des écrans qu'elle ne nomme
 * plus.
 */
function sidebar(string $html): string
{
    // La *première* balise seulement : `Str::between` irait jusqu'au dernier
    // `</aside>` de la page, et une page qui a le sien emporterait l'assertion
    // avec elle.
    return Str::before(Str::after($html, '<aside'), '</aside>');
}

test('the nav names six doors, then Hermès and the settings', function () {
    $this->get(route('today'))->assertSeeInOrder([
        "Aujourd'hui",
        'À trier',
        'Planning',
        'Objectifs',
        'Mémoire',
        'Recul',
        // Hermès n'est pas une septième porte : il descend avec les Réglages.
        'Hermès',
        'Réglages',
    ]);
});

test('the sixteen screens are gone from the nav, they are tabs now', function () {
    $sidebar = sidebar($this->get(route('today'))->getContent());

    // Les écrans existent toujours, à leur URL — mais la barre latérale ne les
    // nomme plus : ils sont des onglets de leur espace.
    expect($sidebar)->not->toContain('Mois')
        ->and($sidebar)->not->toContain('Toutes les tâches')
        ->and($sidebar)->not->toContain('Listes')
        ->and($sidebar)->not->toContain('Entités')
        ->and($sidebar)->not->toContain('Bilan de vie')
        ->and($sidebar)->not->toContain('Missions')
        ->and($sidebar)->not->toContain('Skills');
});

test('a space entry shows a number only when I am the one blocking', function () {
    InboxItem::factory()->count(3)->create(['processed_at' => null]);
    OpenDecision::factory()->count(2)->create(['status' => OpenDecisionStatus::Open]);
    BrainSuggestion::factory()->count(4)->create(['status' => BrainSuggestionStatus::Pending]);

    $html = sidebar($this->get(route('today'))->getContent());

    // L'inbox à trier et une décision à trancher portent leur chiffre ; ce que
    // le curateur a produit se contente d'un point — on verra le 4 sur
    // l'onglet Notes, là où on agit dessus.
    expect($html)->toContain('bg-(--aibg) text-(--ai)">3')
        ->and($html)->toContain('bg-(--acbg) text-(--ac)">2')
        ->and($html)->not->toContain('bg-(--aibg) text-(--ai)">4');
});

test('something waiting inside an agent space is a dot, not a number', function () {
    $this->get(route('today'))->assertDontSee('une revue ou un bilan vous attend');

    Review::factory()->create(['completed_at' => null]);

    $this->get(route('today'))->assertSee('une revue ou un bilan vous attend');
});

test('the calendar marks the space it belongs to, and that space only', function () {
    $html = $this->get(route('calendar.index'))->getContent();

    // Un seul espace allumé dans la barre latérale, et c'est « Planning » ; le
    // mois s'allume une fois de plus comme onglet de cet espace.
    expect(substr_count(sidebar($html), 'aria-current="page"'))->toBe(1)
        ->and(sidebar($html))->toContain('Planning')
        ->and(substr_count($html, 'aria-current="page"'))->toBe(3);
});

test('each work screen offers the other doors of its space', function () {
    $this->get(route('tasks.index'))->assertSeeInOrder(['Mois', 'Toutes les tâches']);
    $this->get(route('goals.index'))->assertSeeInOrder(['Objectifs', 'Décisions']);
    $this->get(route('entities.index'))->assertSeeInOrder(['Notes', 'Entités', 'Listes']);
    $this->get(route('missions.index'))->assertSeeInOrder(['Missions', 'Équipe', 'Skills', 'Système']);
});

/**
 * « À trier » est la seule porte à n'avoir qu'un écran : le chiffre qui y
 * attend est le seul dont je suis le bloquant tous les jours, il ne doit pas
 * se cacher sous un onglet d'Aujourd'hui.
 */
test('a door with a single screen shows no tab bar at all', function () {
    $html = $this->get(route('inbox.index'))->getContent();

    // La barre latérale et la barre du bas, et rien de plus : pas d'onglet.
    expect(substr_count($html, 'aria-current="page"'))->toBe(2)
        ->and(sidebar($html))->toContain('À trier');
});

test('reading screens carry their tabs in their own column', function () {
    $this->get(route('review.index'))->assertSeeInOrder(['Revue', 'Bilan de vie']);
    $this->get(route('bilan.index'))->assertSeeInOrder(['Revue', 'Bilan de vie']);
});

/**
 * Les agents système ont quitté les Réglages pour devenir un onglet de l'espace
 * Agents, le jour où on a pu missionner une équipe : une mission qu'on lance et
 * qu'on regarde tourner est un geste, pas un réglage.
 */
test('agents became a space of their own, and the settings screen still points at it', function () {
    $this->get(route('settings.index'))->assertSee('Ouvrir les agents');

    $agents = $this->get(route('agents.index'));

    $agents->assertSee('Agents système')
        ->assertDontSee('← Réglages', false)
        ->assertSeeInOrder(['Missions', 'Équipe', 'Skills', 'Système']);
});

test('the Hermès space marks itself in the nav, on each of its four tabs', function () {
    foreach (['missions.index', 'team.index', 'skills.index', 'agents.index'] as $route) {
        $sidebar = sidebar($this->get(route($route))->getContent());

        expect(substr_count($sidebar, 'aria-current="page"'))->toBe(1)
            ->and($sidebar)->toContain('Hermès');
    }
});

test('a running mission puts a dot on the Hermès space, never a number', function () {
    $this->get(route('today'))->assertDontSee('mission en cours');

    Mission::factory()->running()->create();

    $html = sidebar($this->get(route('today'))->getContent());

    // Ce qu'un agent fait est un point : je ne suis pas le bloquant.
    expect($html)->toContain('mission en cours')
        ->and($html)->not->toContain('bg-(--aibg) text-(--ai)">1');
});
