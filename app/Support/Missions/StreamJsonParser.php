<?php

namespace App\Support\Missions;

/**
 * Le flux `--output-format stream-json` est du JSONL : un événement par ligne.
 *
 * Les morceaux que `Process` nous passe ne sont pas alignés sur les lignes — un
 * objet JSON peut arriver coupé en deux, et un seul morceau peut en contenir
 * dix. D'où le tampon : on découpe sur les sauts de ligne, on garde le reste
 * pour le morceau suivant, et une ligne indécodable est ignorée plutôt que de
 * faire tomber la mission (le CLI écrit parfois du bruit sur sa sortie).
 */
class StreamJsonParser
{
    private string $buffer = '';

    /**
     * @return list<array<string, mixed>>
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;

        $lines = explode("\n", $this->buffer);

        // La dernière tranche n'est une ligne complète que si le morceau
        // finissait par un saut de ligne ; sinon elle retourne au tampon.
        $this->buffer = (string) array_pop($lines);

        return $this->decode($lines);
    }

    /**
     * Ce qui reste dans le tampon quand le process est fini : une dernière
     * ligne sans saut de ligne final est un événement comme les autres.
     *
     * @return list<array<string, mixed>>
     */
    public function flush(): array
    {
        $remaining = $this->buffer;
        $this->buffer = '';

        return $this->decode([$remaining]);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function decode(array $lines): array
    {
        $events = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }
}
