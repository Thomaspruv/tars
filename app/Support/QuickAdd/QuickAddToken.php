<?php

namespace App\Support\QuickAdd;

/**
 * Un fragment reconnu dans la ligne de capture. `raw` est le texte tel que je
 * l'ai tapé, `label` ce que la puce affiche : le nom de l'entité trouvée, la
 * date mise en forme, l'heure en français. Les deux diffèrent presque toujours
 * — c'est précisément ce que la puce montre pendant la frappe.
 */
final readonly class QuickAddToken
{
    public function __construct(
        /** date | time | entity | goal | priority | recurrence */
        public string $kind,
        public string $raw,
        public string $label,
    ) {}
}
