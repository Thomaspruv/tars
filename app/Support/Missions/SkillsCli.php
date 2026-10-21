<?php

namespace App\Support\Missions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Le CLI de skills.sh, appelé depuis PHP.
 *
 * Il exige Node >= 22.20 ; le `node` par défaut de la machine est en v20 et
 * échoue sur un import de `fs/promises`. On préfixe donc le PATH du Node 22
 * d'nvm — c'est la seule subtilité, et elle n'a qu'un endroit où vivre.
 */
class SkillsCli
{
    /**
     * @param  list<string>  $arguments
     * @return array{ok: bool, output: string}
     */
    public function run(array $arguments, int $timeout = 180): array
    {
        $result = Process::env(ShellPath::env((string) config('missions.node_path')))
            // Sous PHP-FPM le répertoire courant est `public/` : on n'y lance
            // rien, et surtout pas un outil qui sait installer des fichiers.
            ->path(base_path())
            ->timeout($timeout)
            ->run(['npx', '-y', 'skills@latest', ...$arguments]);

        $output = $this->readable($result->output().PHP_EOL.$result->errorOutput());

        if (! $result->successful()) {
            // Un outil externe qui échoue depuis le serveur web et marche dans
            // un terminal, c'est presque toujours l'environnement : on garde
            // donc ce qu'il a dit, plutôt que de le deviner.
            Log::warning('skills.sh CLI failed', [
                'arguments' => $arguments,
                'exit_code' => $result->exitCode(),
                'output' => mb_substr($output, 0, 2000),
            ]);
        }

        return [
            'ok' => $result->successful(),
            'output' => $output,
        ];
    }

    /**
     * Le CLI écrit pour un terminal : des couleurs, mais aussi des codes qui
     * cachent le curseur, effacent la ligne et réécrivent par-dessus pour
     * animer un compteur. Recopiés tels quels dans une page web, ils donnent
     * une bouillie de `[?25l` et de `[1G`.
     */
    private function readable(string $output): string
    {
        // Toutes les séquences d'échappement, pas seulement les couleurs.
        $output = preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $output) ?? $output;
        // Un échappement qui traînerait encore (titre de fenêtre, cloche).
        $output = preg_replace('/\e[^a-zA-Z]*[a-zA-Z]?/', '', $output) ?? $output;

        // Une ligne réécrite par retour chariot : seule la dernière version
        // comptait à l'écran, les autres étaient déjà effacées.
        $output = preg_replace('/^.*\r(?!\n)/m', '', $output) ?? $output;

        return trim($output);
    }
}
