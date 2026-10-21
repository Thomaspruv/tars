<?php

namespace App\Support\Missions;

use Illuminate\Support\Facades\Http;

/**
 * La sentinelle : une vérification bête, qui garde la porte de l'agent.
 *
 * Réveiller un agent pour constater qu'une page n'a pas bougé coûte ~0,35 $ ;
 * aller chercher la page et comparer une empreinte coûte zéro. La sentinelle
 * fait donc le guet, et l'agent n'est appelé que pour juger — ce qu'il est le
 * seul à savoir faire.
 *
 * L'empreinte est prise sur un texte normalisé : scripts, styles, commentaires
 * et espaces sautent. Sans ça, un jeton anti-CSRF ou un horodatage caché dans
 * la page suffirait à déclarer un changement à chaque passage, et la sentinelle
 * ne garderait plus rien.
 */
class Sentinel
{
    public function fingerprint(string $url): ?string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Tars/1.0 (+veille)'])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return hash('sha256', $this->normalise($response->body()));
    }

    private function normalise(string $html): string
    {
        $text = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? $text;
        $text = strip_tags($text);

        // Ce qui change à chaque requête sans rien vouloir dire : jetons,
        // identifiants de session, horodatages.
        $text = preg_replace('/\b[0-9a-f]{16,}\b/i', ' ', $text) ?? $text;
        $text = preg_replace('/\d{1,2}:\d{2}(:\d{2})?/', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
