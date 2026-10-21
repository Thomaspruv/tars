<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('settings.index'))->assertRedirect(route('login'));
});

/**
 * Écran 13 du doc : trois groupes dans la colonne de gauche, et deux entrées
 * qu'on n'écrit pas parce que rien ne les porte en base — les domaines de vie
 * (modèle retiré en juillet 2026) et la matrice de permissions d'Hermès.
 */
test('the settings index groups its sections and promises nothing it cannot keep', function () {
    $this->actingAs(User::factory()->create());

    $html = $this->get(route('settings.index'))->getContent();

    expect($html)->toContain('Objectifs &amp; teintes')
        ->and($html)->toContain('Notifications')
        ->and($html)->toContain('Revue &amp; bilan')
        ->and($html)->not->toContain('Domaines de vie')
        ->and($html)->not->toContain("Ce qu'Hermès peut faire");
});

test('the alert webhook has its own section rather than hiding under the MCP server', function () {
    $this->actingAs(User::factory()->create());

    $html = $this->get(route('settings.index'))->getContent();

    expect($html)->toContain('id="notifications"')
        ->and(strpos($html, 'id="notifications"'))->toBeLessThan(strpos($html, 'id="mcp"'));
});
