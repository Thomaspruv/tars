<?php

namespace App\Support\Missions;

use Illuminate\Support\Facades\Process;

/**
 * Le lancement du CLI, et rien d'autre.
 *
 * Isolé pour deux raisons : le job reste lisible, et l'arrêt d'une mission a un
 * seul endroit où vivre. On surveille le process plutôt que d'attendre sa fin,
 * pour pouvoir lui envoyer un signal quand Thomas clique sur « Arrêter ».
 */
class ClaudeProcess
{
    private const int SIGTERM = 15;

    private const int SIGKILL = 9;

    private const float GRACE_SECONDS = 10.0;

    /**
     * @param  list<string>  $command
     * @param  callable(string): void  $onOutput
     * @param  callable(int): void  $onStarted
     * @param  callable(): bool  $shouldStop
     */
    public function run(
        array $command,
        string $workingDirectory,
        callable $onOutput,
        callable $onStarted,
        callable $shouldStop,
    ): MissionOutcome {
        $process = Process::path($workingDirectory)
            ->env($this->environment($command[0] ?? ''))
            ->forever()
            ->start($command, function (string $type, string $chunk) use ($onOutput): void {
                // La sortie d'erreur du CLI n'est pas du flux : elle est gardée
                // par `Process` et relue à la fin, en cas d'échec.
                if ($type === 'out') {
                    $onOutput($chunk);
                }
            });

        $onStarted((int) $process->id());

        $signalledAt = null;

        while ($process->running()) {
            if ($signalledAt === null && $shouldStop()) {
                $process->signal(self::SIGTERM);
                $signalledAt = microtime(true);
            } elseif ($signalledAt !== null && microtime(true) - $signalledAt > self::GRACE_SECONDS) {
                // Un agent au milieu d'un appel réseau peut ignorer un SIGTERM.
                // Passé le délai de grâce on ne négocie plus : sans ça, le
                // worker attendrait indéfiniment une mission déjà arrêtée à
                // l'écran.
                $process->signal(self::SIGKILL);
                $signalledAt = microtime(true) + self::GRACE_SECONDS;
            }

            usleep(400_000);
        }

        $result = $process->wait();

        return new MissionOutcome(
            exitCode: (int) $result->exitCode(),
            errorOutput: trim($result->errorOutput()),
            stopped: $signalledAt !== null,
        );
    }

    /**
     * Le worker de queue n'a pas forcément le PATH d'un terminal : on y remet
     * le dossier du binaire, et les dossiers système où vivent git et les
     * outils dont un agent peut avoir besoin.
     *
     * @return array<string, string>
     */
    private function environment(string $binary): array
    {
        return ShellPath::env($binary !== '' ? dirname($binary) : '');
    }
}
