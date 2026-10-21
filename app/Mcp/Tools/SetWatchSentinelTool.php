<?php

namespace App\Mcp\Tools;

use App\Support\Mcp\CallingMission;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_watch_sentinel')]
#[Description(
    "Si ce que tu surveilles tient dans une page web, donne-en l'adresse : Tars ira "
    .'la chercher lui-même entre deux de tes passages et ne te réveillera que si elle '
    ."a changé. Tu économises ainsi l'essentiel de tes réveils — et donc de ton budget "
    .'— sans rien perdre. À appeler dès que tu as trouvé la bonne page, et à rappeler '
    .'si tu t\'aperçois que ce n\'était pas la bonne.'
)]
class SetWatchSentinelTool extends LoggedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description("L'adresse de la page qui dira que c'est arrivé.")
                ->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['url' => ['required', 'string', 'max:2000', 'url']]);

        $mission = CallingMission::get();

        if ($mission === null) {
            return Response::error("Cet outil n'a de sens qu'appelé depuis une mission en veille.");
        }

        if (! $mission->isWatching()) {
            return Response::error("Cette mission n'est pas une veille : il n'y a rien à garder.");
        }

        // L'empreinte repart de zéro : la page vient peut-être de changer de
        // nature, et comparer avec l'ancienne ne voudrait plus rien dire.
        $mission->forceFill([
            'watch_url' => $validated['url'],
            'watch_fingerprint' => null,
            'watch_skips' => 0,
        ])->save();

        return Response::text(
            'Sentinelle posée sur '.$validated['url'].'. '
            ."Tars la vérifiera toutes les {$mission->watch_every_minutes} minutes et ne te réveillera "
            .'que si elle bouge.'
        );
    }
}
