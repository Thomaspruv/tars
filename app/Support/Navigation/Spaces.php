<?php

namespace App\Support\Navigation;

/**
 * Les cinq espaces de l'app.
 *
 * L'app a quatorze écrans mais cinq gestes seulement : tenir ma journée,
 * faire, suivre où je vais, retrouver la matière, regarder en arrière. La
 * barre latérale ne nomme donc que ces cinq-là ; chaque écran reste à son
 * URL et devient un onglet de son espace. Un espace = un verbe ou un
 * résultat, jamais un nom d'objet de la base.
 *
 * Source unique : la sidebar desktop, la barre d'onglets mobile et
 * `<x-space-tabs />` lisent tous cette liste — il n'y a pas deux endroits où
 * décider ce que le menu contient.
 *
 * Un espace ne porte qu'un signal, et un chiffre seulement quand je suis le
 * bloquant (l'inbox à trier, une décision à trancher) ; ce qu'un agent a
 * produit se contente d'un point. Les chiffres exacts vivent sur les onglets,
 * c'est-à-dire là où on agit dessus.
 *
 * @phpstan-type Signal array{key: string, as: 'count'|'dot'}
 * @phpstan-type Tab array{label: string, route: string, also: list<string>, signal: string|null}
 * @phpstan-type Space array{key: string, label: string, signal: Signal|null, tabs: non-empty-list<Tab>}
 */
final class Spaces
{
    /**
     * @return non-empty-list<Space>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'jour',
                'label' => 'Aujourd\'hui',
                'signal' => ['key' => 'inbox', 'as' => 'count'],
                'tabs' => [
                    ['label' => 'Aujourd\'hui', 'route' => 'today', 'also' => [], 'signal' => null],
                    ['label' => 'Inbox', 'route' => 'inbox.index', 'also' => ['share.target'], 'signal' => 'inbox'],
                ],
            ],
            [
                'key' => 'faire',
                'label' => 'Faire',
                'signal' => null,
                'tabs' => [
                    ['label' => 'Tâches', 'route' => 'tasks.index', 'also' => [], 'signal' => null],
                    ['label' => 'Calendrier', 'route' => 'calendar.index', 'also' => [], 'signal' => null],
                    ['label' => 'Listes', 'route' => 'lists.index', 'also' => [], 'signal' => null],
                ],
            ],
            [
                'key' => 'objectifs',
                'label' => 'Objectifs',
                'signal' => ['key' => 'decisions', 'as' => 'count'],
                'tabs' => [
                    ['label' => 'Objectifs', 'route' => 'goals.index', 'also' => ['goals.show'], 'signal' => null],
                    ['label' => 'Décisions', 'route' => 'decisions.index', 'also' => ['decisions.show'], 'signal' => 'decisions'],
                ],
            ],
            [
                'key' => 'cerveau',
                'label' => 'Cerveau',
                'signal' => ['key' => 'curator', 'as' => 'dot'],
                'tabs' => [
                    ['label' => 'Entités', 'route' => 'entities.index', 'also' => ['entities.show'], 'signal' => null],
                    ['label' => 'Notes', 'route' => 'brain.index', 'also' => [], 'signal' => 'curator'],
                ],
            ],
            [
                'key' => 'revue',
                'label' => 'Revue',
                'signal' => ['key' => 'revue', 'as' => 'dot'],
                'tabs' => [
                    ['label' => 'Revue', 'route' => 'review.index', 'also' => [], 'signal' => 'review'],
                    ['label' => 'Bilan de vie', 'route' => 'bilan.index', 'also' => ['bilan.questionnaire.edit', 'bilan.run.fill'], 'signal' => 'bilan'],
                ],
            ],
        ];
    }

    /**
     * @return Space
     */
    public static function find(string $key): array
    {
        foreach (self::all() as $space) {
            if ($space['key'] === $key) {
                return $space;
            }
        }

        throw new \InvalidArgumentException("Espace inconnu : {$key}");
    }

    /**
     * L'espace auquel appartient la route courante, `null` sur les écrans
     * transverses (Réglages, Agents, compte).
     *
     * @return Space|null
     */
    public static function current(): ?array
    {
        foreach (self::all() as $space) {
            foreach ($space['tabs'] as $tab) {
                if (request()->routeIs($tab['route'], ...$tab['also'])) {
                    return $space;
                }
            }
        }

        return null;
    }

    /**
     * Toutes les routes d'un espace, pour l'état actif de la barre latérale.
     *
     * @param  Space  $space
     * @return list<string>
     */
    public static function routesOf(array $space): array
    {
        $routes = [];

        foreach ($space['tabs'] as $tab) {
            $routes[] = $tab['route'];
            $routes = [...$routes, ...$tab['also']];
        }

        return $routes;
    }
}
