<?php

use App\Enums\OpenDecisionStatus;
use App\Models\InboxItem;
use App\Models\OpenDecision;
use App\Models\Review;
use App\Models\User;

beforeEach(fn () => test()->actingAs(User::factory()->create()));

test('the nav names its three groups and keeps its fourteen destinations in order', function () {
    $this->get(route('today'))->assertSeeInOrder([
        'Faire',
        "Aujourd'hui",
        'Inbox',
        'Tâches',
        'Calendrier',
        'Suivre',
        'Objectifs',
        'Entités',
        'Listes',
        'Réfléchir',
        'Décisions',
        'Revue',
        'Cerveau',
        'Bilan de vie',
        'Agents',
        'Réglages',
    ]);
});

test('the inbox count is the agent green, a decision to settle is mine', function () {
    InboxItem::factory()->count(3)->create(['processed_at' => null]);
    OpenDecision::factory()->count(2)->create(['status' => OpenDecisionStatus::Open]);

    $html = $this->get(route('today'))->getContent();

    // Le vert est le compte de l'agent, l'abricot le mien : une décision à
    // trancher n'attend personne d'autre que moi.
    expect($html)->toContain('bg-(--aibg) text-(--ai)">3')
        ->and($html)->toContain('bg-(--acbg) text-(--ac)">2');
});

test('a review waiting is a dot, not a number', function () {
    $this->get(route('today'))->assertDontSee('une revue vous attend');

    Review::factory()->create(['completed_at' => null]);

    $this->get(route('today'))->assertSee('une revue vous attend');
});

test('the current screen is the only one marked as such', function () {
    $html = $this->get(route('inbox.index'))->getContent();

    expect(substr_count($html, 'bg-(--surf2) font-semibold text-(--tx)'))->toBe(1);
});
