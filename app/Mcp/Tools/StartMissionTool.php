<?php

namespace App\Mcp\Tools;

use App\Models\TeamAgent;
use App\Support\Missions\MissionLauncher;
use App\Support\Missions\SkillCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('start_mission')]
#[Description(
    "Confie un travail à l'équipe d'agents de Thomas. Le chef reçoit le message et "
    ."choisit lui-même qui s'en occupe — ne désigne personne. Les agents travaillent "
    .'en arrière-plan avec tous les droits dans leur répertoire : utilise cet outil '
    .'pour ce qui demande de lire des fichiers, écrire dans le cerveau ou agir, pas '
    ."pour répondre à une question que tu sais traiter toi-même. La réponse n'est pas "
    .'immédiate : relis-la avec get_mission.'
)]
class StartMissionTool extends LoggedTool
{
    public function __construct(
        private readonly MissionLauncher $launcher = new MissionLauncher,
        private readonly SkillCatalog $skills = new SkillCatalog,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()
                ->description("Ce que tu demandes à l'équipe, en français, comme à un humain.")
                ->required(),
            'skill' => $schema->string()
                ->description("Nom exact d'un skill installé à charger pour ce travail. Facultatif."),
            'budget' => $schema->number()
                ->description('Plafond de dépense en dollars pour cette mission. Facultatif.'),
            'watch_every_minutes' => $schema->integer()
                ->description(
                    'Pour une surveillance : toutes les combien de minutes revérifier. '
                    ."L'agent reprendra la même conversation et se souviendra de ses passages. "
                    .'Facultatif, minimum un quart d\'heure.'
                ),
            'watch_url' => $schema->string()
                ->description(
                    'Si la surveillance porte sur une page web, son adresse : Tars la vérifiera '
                    ."lui-même entre deux passages et ne réveillera l'agent que si elle change. "
                    .'Cela divise le coût de la veille par vingt ou plus.'
                ),
            'watch_for_hours' => $schema->integer()
                ->description('Pendant combien d\'heures surveiller. Par défaut une semaine.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:10000'],
            'skill' => ['nullable', 'string', 'max:255'],
            'budget' => ['nullable', 'numeric', 'min:0.1', 'max:'.config('missions.max_budget_usd')],
            'watch_every_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'watch_for_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'watch_url' => ['nullable', 'string', 'max:2000', 'url'],
        ]);

        $chief = TeamAgent::chief();

        if ($chief === null) {
            return Response::error("Aucun agent disponible : l'équipe est vide ou tout le monde est au repos.");
        }

        $skill = $validated['skill'] ?? null;

        if ($skill !== null && $skill !== '' && $this->skills->find($skill) === null) {
            return Response::error("Le skill « {$skill} » n'est pas installé.");
        }

        $mission = $this->launcher->open(
            agent: $chief,
            message: $validated['message'],
            skillSlug: $skill,
            budgetUsd: isset($validated['budget']) ? (float) $validated['budget'] : null,
            source: 'hermes',
        );

        if (! empty($validated['watch_every_minutes'])) {
            $this->launcher->watch(
                mission: $mission,
                everyMinutes: (int) $validated['watch_every_minutes'],
                forHours: isset($validated['watch_for_hours']) ? (int) $validated['watch_for_hours'] : null,
                url: $validated['watch_url'] ?? null,
            );

            $mission->refresh();
        }

        $sentinel = $mission->hasSentinel()
            ? ' Tars garde '.$mission->watch_url.' et ne le réveillera que si la page bouge.'
            : '';

        $watch = $mission->isWatching()
            ? " Il revérifiera toutes les {$mission->watch_every_minutes} minutes jusqu'au "
                .$mission->watch_until?->translatedFormat('d M à H\hi')
                .', et appellera alert_thomas dès que ce sera bon.'
            : '';

        return Response::text(
            "Mission #{$mission->id} confiée à {$chief->name}.{$watch}{$sentinel} "
            .'Elle démarre dès que le worker la prend ; relis-la avec get_mission. '
            .'Thomas la suit sur '.route('missions.show', $mission).'.'
        );
    }
}
