<?php

namespace App\Mcp\Tools;

use App\Models\Mission;
use App\Models\MissionEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_mission')]
#[Description(
    "Où en est une mission confiée à l'équipe, et ce qu'elle a rendu. Sans "
    ."identifiant, renvoie la plus récente. Une mission qui n'a pas encore abouti "
    ."n'a pas de réponse : dis à Thomas qu'elle travaille encore plutôt que "
    .'de lui inventer un résultat.'
)]
class GetMissionTool extends LoggedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Le numéro de la mission. Facultatif : la plus récente par défaut.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['id' => ['nullable', 'integer']]);

        $mission = Mission::with('teamAgent')
            ->when(isset($validated['id']), fn ($query) => $query->whereKey($validated['id']))
            ->latest('id')
            ->first();

        if ($mission === null) {
            return Response::error(isset($validated['id'])
                ? "Aucune mission #{$validated['id']}."
                : "Aucune mission n'a encore été confiée à l'équipe.");
        }

        $lines = [
            "Mission #{$mission->id} — {$mission->teamAgent->name} — {$mission->status->label()}.",
            'Demandé : '.str($mission->prompt)->limit(200)->toString(),
        ];

        if ($mission->status->isOpen()) {
            $step = $this->currentStep($mission);

            $lines[] = $step !== null ? "En ce moment : {$step}" : "Elle n'a pas encore commencé.";
        }

        if ($mission->result !== null && $mission->result !== '') {
            $lines[] = 'Réponse : '.$mission->result;
        }

        if ($mission->error !== null) {
            $lines[] = 'Erreur : '.$mission->error;
        }

        if ($mission->isWatching()) {
            $lines[] = 'Veille active : toutes les '.$mission->watch_every_minutes.' minutes, '
                .$mission->watch_runs.' '.str('passage')->plural($mission->watch_runs)->toString().' déjà faits.';
        } elseif ($mission->watch_ended_reason !== null) {
            $lines[] = 'Veille terminée : '.$mission->watch_ended_reason->label().'.';
        }

        if ($mission->cost_usd !== null) {
            $lines[] = 'Coût : '.number_format((float) $mission->cost_usd, 2).' $ sur '
                .$mission->turn.' '.str('message')->plural($mission->turn)->toString().'.';
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * La dernière étape qui dise quelque chose — ce que l'agent est en train de
     * faire, pour qu'Hermès puisse le raconter sans ouvrir Tars.
     */
    private function currentStep(Mission $mission): ?string
    {
        $event = MissionEvent::where('mission_id', $mission->id)
            ->whereIn('subtype', ['text', 'tool_use'])
            ->orderByDesc('seq')
            ->first();

        if ($event === null) {
            return null;
        }

        if ($event->isDelegation()) {
            return 'il missionne un coéquipier — '.$event->text;
        }

        return $event->subtype === 'tool_use'
            ? $event->tool_name.' — '.str($event->text)->limit(120)->toString()
            : str($event->text)->limit(200)->toString();
    }
}
