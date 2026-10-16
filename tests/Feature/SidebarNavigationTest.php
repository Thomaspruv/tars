<?php

use App\Enums\BrainSuggestionStatus;
use App\Enums\OpenDecisionStatus;
use App\Models\BrainSuggestion;
use App\Models\InboxItem;
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
    return Str::between($html, '<aside', '</aside>');
}

test('the nav names five spaces and nothing else', function () {
    $this->get(route('today'))->assertSeeInOrder([
        "Aujourd'hui",
        'Faire',
        'Objectifs',
        'Cerveau',
        'Revue',
        'Réglages',
    ]);
});

test('the fourteen screens are gone from the nav, they are tabs now', function () {
    $sidebar = sidebar($this->get(route('today'))->getContent());

    // Les écrans existent toujours, à leur URL — mais la barre latérale ne les
    // nomme plus : ils sont des onglets de leur espace.
    expect($sidebar)->not->toContain('Calendrier')
        ->and($sidebar)->not->toContain('Listes')
        ->and($sidebar)->not->toContain('Entités')
        ->and($sidebar)->not->toContain('Inbox')
        ->and($sidebar)->not->toContain('Bilan de vie')
        ->and($sidebar)->not->toContain('Agents');
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

    // Un seul espace allumé dans la barre latérale, et c'est « Faire » ; le
    // Calendrier s'allume une fois de plus comme onglet de cet espace.
    expect(substr_count(sidebar($html), 'aria-current="page"'))->toBe(1)
        ->and(sidebar($html))->toContain('Faire')
        ->and(substr_count($html, 'aria-current="page"'))->toBe(3);
});

test('each work screen offers the other doors of its space', function () {
    $this->get(route('tasks.index'))->assertSeeInOrder(['Tâches', 'Calendrier', 'Listes']);
    $this->get(route('inbox.index'))->assertSeeInOrder(["Aujourd'hui", 'Inbox']);
    $this->get(route('goals.index'))->assertSeeInOrder(['Objectifs', 'Décisions']);
    $this->get(route('entities.index'))->assertSeeInOrder(['Entités', 'Notes']);
});

test('reading screens carry their tabs in their own column', function () {
    $this->get(route('review.index'))->assertSeeInOrder(['Revue', 'Bilan de vie']);
    $this->get(route('bilan.index'))->assertSeeInOrder(['Revue', 'Bilan de vie']);
});

test('agents moved under the settings screen', function () {
    $this->get(route('settings.index'))->assertSee('Ouvrir les agents');
    $this->get(route('agents.index'))->assertSee('← Réglages', false);
});
