<?php

namespace App\Support\Proposals;

/**
 * Une proposition du rail « TARS propose ». L'agent dit ce qu'il a vu et
 * propose une suite — il n'applique rien : `primaryAction` est toujours un
 * écran à ouvrir, et `secondaryLabel` la sortie « non ».
 */
final readonly class Proposal
{
    public function __construct(
        /** Clé stable : elle sert aussi de clé d'ignorance pendant 7 jours. */
        public string $source,
        public string $meta,
        public string $text,
        public string $primaryLabel,
        public string $primaryAction,
        public ?string $secondaryLabel = null,
    ) {}
}
