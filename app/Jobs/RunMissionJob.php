<?php

namespace App\Jobs;

use App\Enums\MissionStatus;
use App\Models\Mission;
use App\Support\Missions\ClaudeProcess;
use App\Support\Missions\MissionCommandBuilder;
use App\Support\Missions\MissionEventRecorder;
use App\Support\Missions\StreamJsonParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fait tourner une mission, et en range le flux au fil de l'eau.
 *
 * Sur la file `missions`, seule : une mission dure des minutes, et elle ne doit
 * pas retenir derrière elle la poussée du vault ni les agents système.
 *
 * Quoi qu'il arrive, la mission finit avec un statut : une mission qui tourne
 * encore alors que le process est mort serait un mensonge affiché à l'écran.
 */
class RunMissionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $missionId)
    {
        $this->onQueue('missions');
    }

    public function handle(
        MissionCommandBuilder $builder,
        ClaudeProcess $claude,
    ): void {
        $mission = Mission::with('teamAgent')->find($this->missionId);

        if ($mission === null || $mission->status !== MissionStatus::Queued) {
            return;
        }

        $mission->forceFill([
            'status' => MissionStatus::Running,
            'started_at' => now(),
        ])->save();

        $parser = new StreamJsonParser;
        $recorder = new MissionEventRecorder($mission);

        try {
            $outcome = $claude->run(
                command: $builder->build($mission),
                workingDirectory: $mission->workspace_path,
                onOutput: function (string $chunk) use ($parser, $recorder): void {
                    foreach ($parser->feed($chunk) as $event) {
                        $recorder->record($event);
                    }
                },
                onStarted: function (int $pid) use ($mission): void {
                    $mission->forceFill(['pid' => $pid])->save();
                },
                shouldStop: fn (): bool => (bool) Mission::where('id', $mission->id)->value('cancel_requested'),
            );

            foreach ($parser->flush() as $event) {
                $recorder->record($event);
            }
        } catch (\Throwable $e) {
            $this->markFailed($mission, $e->getMessage());

            return;
        }

        $mission->refresh();

        if ($outcome->stopped) {
            $mission->forceFill([
                'status' => MissionStatus::Cancelled,
                'finished_at' => $mission->finished_at ?? now(),
            ])->save();

            return;
        }

        // L'événement `result` a normalement déjà clos la mission. S'il n'est
        // jamais venu — process tué, budget épuisé, binaire introuvable — c'est
        // ici qu'on le constate plutôt que de la laisser « en cours » pour
        // toujours.
        if ($mission->status === MissionStatus::Running) {
            $outcome->successful()
                ? $mission->forceFill(['status' => MissionStatus::Succeeded, 'finished_at' => now()])->save()
                : $this->markFailed($mission, $outcome->errorOutput !== '' ? $outcome->errorOutput : "Le CLI s'est arrêté avec le code {$outcome->exitCode}.");
        }
    }

    public function failed(\Throwable $exception): void
    {
        $mission = Mission::find($this->missionId);

        if ($mission !== null && $mission->status->isOpen()) {
            $this->markFailed($mission, $exception->getMessage());
        }
    }

    private function markFailed(Mission $mission, string $error): void
    {
        $mission->forceFill([
            'status' => MissionStatus::Failed,
            'error' => mb_substr($error, 0, 1000),
            'finished_at' => now(),
        ])->save();
    }
}
