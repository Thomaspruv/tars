<?php

namespace App\Support;

/**
 * Les sept teintes de saison de la direction « Niveaux · Tenue ».
 *
 * Elles sont assez claires pour se poser sur le fond sombre et assez foncées
 * pour porter du texte noir en light — c'est pourquoi les deux thèmes
 * partagent exactement les mêmes valeurs (voir `resources/css/app.css`). Un
 * objectif garde sa teinte partout : calendrier, tâches, index d'objectifs,
 * fiches. Au-delà de sept objectifs, les suivants réutilisent la série dans
 * le même ordre.
 */
final class Palette
{
    /**
     * Ordre volontaire : deux teintes voisines de la liste ne se ressemblent
     * pas, pour que deux objectifs créés à la suite se distinguent.
     *
     * @var list<string>
     */
    public const array SLOTS = ['wheat', 'brick', 'spring', 'mulberry', 'sprout', 'linden', 'apricot'];

    /**
     * Teinte déterministe pour une graine donnée (en pratique : l'id d'un
     * objectif, ou celui d'un événement sans objectif).
     */
    public static function slot(int $seed): string
    {
        return self::SLOTS[abs($seed) % count(self::SLOTS)];
    }
}
