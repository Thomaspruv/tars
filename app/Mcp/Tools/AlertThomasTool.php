<?php

namespace App\Mcp\Tools;

use App\Enums\WatchEnded;
use App\Jobs\SendAlertJob;
use App\Models\MissionEvent;
use App\Support\Alerts\AlertSettings;
use App\Support\Mcp\CallingMission;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('alert_thomas')]
#[Description(
    'Prévient Thomas sur-le-champ, où qu\'il soit. À n\'utiliser que pour ce qui ne '
    ."peut pas attendre qu'il ouvre Tars : une place qui se libère, une échéance qui "
    ."tombe, un problème qui s'aggrave. Si tu es un agent en veille, appelle-le dès "
    .'que ce que tu surveilles est arrivé : la veille s\'arrête alors toute seule.'
)]
class AlertThomasTool extends LoggedTool
{
    public function __construct(private readonly AlertSettings $settings = new AlertSettings) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()
                ->description('Ce que Thomas doit savoir, en une ou deux phrases, en français.')
                ->required(),
            'link' => $schema->string()->description('Un lien utile : la page où agir. Facultatif.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:2000'],
            'link' => ['nullable', 'string', 'max:2000', 'url'],
        ]);

        $mission = CallingMission::get();

        // L'alerte est une ligne du fil : même si le webhook tombe, Thomas
        // retrouve dans Tars ce que son agent a voulu lui dire.
        if ($mission !== null) {
            MissionEvent::create([
                'mission_id' => $mission->id,
                'seq' => (int) MissionEvent::where('mission_id', $mission->id)->max('seq') + 1,
                'turn' => $mission->turn,
                'type' => 'alert',
                'subtype' => 'alert',
                'text' => $validated['message'],
                'payload' => ['link' => $validated['link'] ?? null],
                'occurred_at' => now(),
            ]);

            // Ce qu'il surveillait est arrivé : la veille n'a plus de raison
            // d'être, et continuer coûterait sans rien apprendre.
            if ($mission->isWatching()) {
                $mission->endWatch(WatchEnded::Found);
            }
        }

        SendAlertJob::dispatch(
            message: $validated['message'],
            missionId: $mission?->id,
            link: $validated['link'] ?? null,
        );

        return Response::text($this->settings->isConfigured()
            ? 'Thomas est prévenu.'
            : "C'est noté dans Tars, mais aucun webhook d'alerte n'est configuré : Thomas ne le verra qu'en ouvrant l'écran.");
    }
}
