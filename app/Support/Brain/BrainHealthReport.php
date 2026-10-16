<?php

namespace App\Support\Brain;

readonly class BrainHealthReport
{
    public function __construct(
        public int $documents,
        public int $underContract,
        public int $conformant,
        public int $conformanceRate,
        public int $misplaced,
        public int $pendingConformanceSuggestions,
        public int $sasNotes,
    ) {}

    /**
     * The one line the weekly review and Hermes both repeat.
     */
    public function sentence(): string
    {
        $suggestions = $this->pendingConformanceSuggestions === 0
            ? 'aucune proposition en attente'
            : "{$this->pendingConformanceSuggestions} proposition(s) en attente";

        return "Mémoire : {$this->conformanceRate} % conforme, {$suggestions}, {$this->sasNotes} note(s) dans le sas.";
    }
}
