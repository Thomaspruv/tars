<?php

namespace App\Support\Navigation;

/**
 * Les six portes de l'app, et Hermès à côté.
 *
 * L'app a seize écrans mais six gestes seulement : tenir ma journée, décider
 * de ce qui est arrivé, placer dans le temps, suivre où je vais, retrouver la
 * matière, regarder en arrière. La barre latérale ne nomme donc que ces
 * six-là ; chaque écran reste à son URL et devient un onglet de sa porte. Une
 * porte = un verbe ou un résultat, jamais un nom d'objet de la base.
 *
 * Les noms viennent du doc de refonte (« Écrans v2 ») et disent le geste, pas
 * la table : « À trier » plutôt qu'Inbox, « Planning » plutôt que Faire,
 * « Mémoire » plutôt que Cerveau, « Recul » plutôt que Revue. « À trier » a sa
 * propre porte parce que le chiffre qui y attend est le seul de l'app dont je
 * suis le bloquant tous les jours — il ne doit pas se cacher sous un onglet.
 *
 * Hermès n'est pas une septième porte : il porte `aside`, et vit donc en bas
 * avec les Réglages. On ne va pas voir l'équipe comme on va voir sa journée —
 * on y va pour missionner, puis pour regarder tourner.
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
 * @phpstan-type Space array{key: string, label: string, short: string, aside: bool, signal: Signal|null, tabs: non-empty-list<Tab>}
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
                // Sous le pouce, « Aujourd'hui » ne tient pas dans un sixième
                // d'écran de 390 : l'onglet dit « Jour ».
                'short' => 'Jour',
                'aside' => false,
                'signal' => null,
                'tabs' => [
                    ['label' => 'Aujourd\'hui', 'route' => 'today', 'also' => [], 'signal' => null],
                ],
            ],
            [
                'key' => 'trier',
                'label' => 'À trier',
                'short' => 'À trier',
                'aside' => false,
                'signal' => ['key' => 'inbox', 'as' => 'count'],
                'tabs' => [
                    ['label' => 'À trier', 'route' => 'inbox.index', 'also' => ['share.target'], 'signal' => 'inbox'],
                ],
            ],
            [
                'key' => 'planning',
                'label' => 'Planning',
                'short' => 'Planning',
                'aside' => false,
                'signal' => null,
                'tabs' => [
                    ['label' => 'Mois', 'route' => 'calendar.index', 'also' => [], 'signal' => null],
                    ['label' => 'Toutes les tâches', 'route' => 'tasks.index', 'also' => [], 'signal' => null],
                ],
            ],
            [
                'key' => 'objectifs',
                'label' => 'Objectifs',
                'short' => 'Objectifs',
                'aside' => false,
                'signal' => ['key' => 'decisions', 'as' => 'count'],
                'tabs' => [
                    ['label' => 'Objectifs', 'route' => 'goals.index', 'also' => ['goals.show'], 'signal' => null],
                    ['label' => 'Décisions', 'route' => 'decisions.index', 'also' => ['decisions.show'], 'signal' => 'decisions'],
                ],
            ],
            [
                'key' => 'memoire',
                'label' => 'Mémoire',
                'short' => 'Mémoire',
                'aside' => false,
                'signal' => ['key' => 'curator', 'as' => 'dot'],
                'tabs' => [
                    ['label' => 'Notes', 'route' => 'brain.index', 'also' => [], 'signal' => 'curator'],
                    ['label' => 'Entités', 'route' => 'entities.index', 'also' => ['entities.show'], 'signal' => null],
                    // Une liste est de la matière qu'on retrouve, pas du temps
                    // qu'on place : elle a quitté Planning pour Mémoire.
                    ['label' => 'Listes', 'route' => 'lists.index', 'also' => [], 'signal' => null],
                ],
            ],
            [
                'key' => 'recul',
                'label' => 'Recul',
                'short' => 'Recul',
                'aside' => false,
                'signal' => ['key' => 'revue', 'as' => 'dot'],
                'tabs' => [
                    ['label' => 'Revue', 'route' => 'review.index', 'also' => [], 'signal' => 'review'],
                    ['label' => 'Bilan de vie', 'route' => 'bilan.index', 'also' => ['bilan.questionnaire.edit', 'bilan.run.fill'], 'signal' => 'bilan'],
                ],
            ],
            [
                'key' => 'hermes',
                'label' => 'Hermès',
                'short' => 'Hermès',
                'aside' => true,
                'signal' => ['key' => 'missions', 'as' => 'dot'],
                'tabs' => [
                    ['label' => 'Missions', 'route' => 'missions.index', 'also' => ['missions.show'], 'signal' => 'missions'],
                    ['label' => 'Équipe', 'route' => 'team.index', 'also' => [], 'signal' => null],
                    ['label' => 'Skills', 'route' => 'skills.index', 'also' => [], 'signal' => null],
                    ['label' => 'Système', 'route' => 'agents.index', 'also' => [], 'signal' => null],
                ],
            ],
        ];
    }

    /**
     * Les portes de la journée — le groupe du haut de la barre latérale.
     *
     * @return non-empty-list<Space>
     */
    public static function doors(): array
    {
        /** @var non-empty-list<Space> */
        return array_values(array_filter(self::all(), fn (array $space): bool => ! $space['aside']));
    }

    /**
     * Ce qui vit en bas, avec les Réglages.
     *
     * @return list<Space>
     */
    public static function aside(): array
    {
        return array_values(array_filter(self::all(), fn (array $space): bool => $space['aside']));
    }

    /**
     * L'espace auquel appartient la route courante, `null` sur les écrans
     * transverses (Réglages, compte).
     *
     * @return Space|null
     */
    public static function current(): ?array
    {
        foreach (self::all() as $space) {
            foreach ($space['tabs'] as $tab) {
                if (CurrentRoute::is($tab['route'], ...$tab['also'])) {
                    return $space;
                }
            }
        }

        return null;
    }

    /**
     * L'espace nommé, ou `null` s'il n'existe pas — pour un composant qui
     * reçoit un nom d'espace en attribut.
     *
     * @return Space|null
     */
    public static function named(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        foreach (self::all() as $space) {
            if ($space['key'] === $key) {
                return $space;
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
