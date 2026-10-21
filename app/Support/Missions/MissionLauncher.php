<?php

namespace App\Support\Missions;

use App\Enums\MissionStatus;
use App\Jobs\RunMissionJob;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Models\TeamAgent;
use Illuminate\Support\Str;

/**
 * Ouvre une conversation avec un agent, et la relance à chaque message.
 *
 * Rien n'est lancé dans la requête web — un agent travaille des minutes, et le
 * CLI a besoin du trousseau de Thomas, que le PHP-FPM de Valet n'a pas. C'est
 * le worker qui le fait tourner (file `missions`).
 */
class MissionLauncher
{
    /**
     * Le premier message d'une conversation.
     */
    public function open(
        TeamAgent $agent,
        string $message,
        ?string $skillSlug = null,
        ?float $budgetUsd = null,
        string $source = 'thomas',
    ): Mission {
        $mission = Mission::create([
            'team_agent_id' => $agent->id,
            'source' => $source === 'hermes' ? 'hermes' : 'thomas',
            'skill_slug' => $skillSlug !== '' ? $skillSlug : null,
            'prompt' => $message,
            'workspace_path' => $agent->workspacePath(),
            'model' => $agent->model,
            'effort' => $agent->effort,
            'max_budget_usd' => $this->budget($budgetUsd),
        ]);

        // L'identifiant de session est le nôtre : c'est lui qui permet de
        // reprendre la conversation au message suivant.
        $mission->forceFill(['session_id' => (string) Str::uuid()])->save();

        $this->record($mission, $message);

        RunMissionJob::dispatch($mission->id);

        return $mission;
    }

    /**
     * Fait de cette conversation une veille : elle se réveillera toute seule
     * jusqu'à ce que l'agent trouve, ou que le délai passe.
     *
     * L'intervalle a un plancher : une veille qui se relance toutes les deux
     * minutes dépense sans rien apprendre de plus.
     */
    public function watch(Mission $mission, int $everyMinutes, ?int $forHours = null, ?string $url = null): Mission
    {
        $minimum = (int) config('missions.watch_min_minutes');

        $mission->forceFill([
            'watch_every_minutes' => max($minimum, $everyMinutes),
            'watch_until' => now()->addHours($forHours !== null && $forHours > 0 ? min($forHours, 24 * 30) : 24 * 7),
            'watch_next_at' => now()->addMinutes(max($minimum, $everyMinutes)),
            'watch_max_usd' => number_format((float) config('missions.watch_max_usd'), 2, '.', ''),
            'watch_url' => $url !== null && trim($url) !== '' ? trim($url) : $mission->watch_url,
            'watch_ended_reason' => null,
        ])->save();

        return $mission;
    }

    /**
     * Repart d'une session CLI neuve au prochain message, en gardant le fil
     * affiché.
     *
     * Une veille qui reprend indéfiniment la même session traîne tous ses
     * passages : au cinquantième réveil, l'agent relit quarante-neuf échanges
     * pour dire « toujours rien », et le prix du passage grimpe avec le
     * contexte. On lui redonne une page blanche et le strict nécessaire.
     */
    public function restartSession(Mission $mission): Mission
    {
        $mission->forceFill([
            'session_id' => (string) Str::uuid(),
            'session_started_turn' => $mission->turn + 1,
        ])->save();

        return $mission;
    }

    /**
     * Le message suivant d'une conversation. On ne parle pas à un agent qui
     * travaille : il faut attendre qu'il ait rendu, ou l'arrêter.
     */
    public function reply(Mission $mission, string $message): bool
    {
        if ($mission->status->isOpen()) {
            return false;
        }

        $mission->forceFill([
            'turn' => $mission->turn + 1,
            'status' => MissionStatus::Queued,
            'cancel_requested' => false,
            'error' => null,
            'finished_at' => null,
        ])->save();

        $this->record($mission, $message);

        RunMissionJob::dispatch($mission->id);

        return true;
    }

    /**
     * Le message de Thomas est une ligne du fil comme les autres : c'est ce qui
     * permet de relire la conversation dans l'ordre, sans table à part.
     */
    private function record(Mission $mission, string $message): void
    {
        MissionEvent::create([
            'mission_id' => $mission->id,
            'seq' => (int) MissionEvent::where('mission_id', $mission->id)->max('seq') + 1,
            'turn' => $mission->turn,
            'type' => 'user',
            'subtype' => MissionEvent::PROMPT,
            'text' => $message,
            'payload' => [],
            'occurred_at' => now(),
        ]);
    }

    private function budget(?float $budgetUsd): string
    {
        $default = (float) config('missions.default_budget_usd');
        $ceiling = (float) config('missions.max_budget_usd');

        $budget = $budgetUsd !== null && $budgetUsd > 0 ? $budgetUsd : $default;

        return number_format(min($budget, $ceiling), 2, '.', '');
    }
}
