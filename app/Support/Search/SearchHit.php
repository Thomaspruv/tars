<?php

namespace App\Support\Search;

/**
 * Une ligne de résultat, déjà résolue en destination : la palette n'a plus
 * qu'à l'afficher et à suivre l'URL.
 */
class SearchHit
{
    public function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $hint,
        public readonly string $url,
        public readonly float $score,
    ) {}
}
