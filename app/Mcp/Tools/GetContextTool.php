<?php

namespace App\Mcp\Tools;

use App\Models\BrainDocument;
use App\Models\Checklist;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\LifeArea;
use App\Support\Brain\BrainSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_context')]
#[Description("Le profil de Thomas (dossier Profil/ du vault) et un résumé de l'état courant (domaines de vie, entités et objectifs actifs). Pensé pour être appelé en début de conversation.")]
class GetContextTool extends LoggedTool
{
    public function __construct(private readonly BrainSettings $settings) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request): Response
    {
        $lifeAreas = LifeArea::count();
        $entities = Entity::where('status', 'active')->count();
        $goals = Goal::where('status', 'active')->count();
        $lists = Checklist::pluck('name');

        $summary = "{$lifeAreas} domaine(s) de vie, {$entities} entité(s) active(s), {$goals} objectif(s) actif(s).";
        $summary .= $lists->isNotEmpty()
            ? ' Listes existantes : '.$lists->implode(', ').'.'
            : ' Aucune liste existante.';

        if (! $this->settings->isConfigured()) {
            return Response::text($summary);
        }

        $profile = BrainDocument::where('path', 'like', 'Profil/%')->get();

        if ($profile->isEmpty()) {
            return Response::text($summary);
        }

        $profileText = $profile->map(fn (BrainDocument $document): string => "{$document->title} : {$document->content}")->implode("\n\n");

        return Response::text("{$profileText}\n\n{$summary}");
    }
}
