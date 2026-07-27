<?php

namespace App\Mcp\Tools;

use App\Models\Entity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_entities_dashboard')]
#[Description("Vue d'ensemble agrégée de toutes les entités actives : tâches ouvertes, prochaine échéance, objectifs liés, relations.")]
class GetEntitiesDashboardTool extends LoggedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request): Response
    {
        $entities = Entity::where('status', 'active')
            ->with(['goals', 'relations', 'relatedFrom'])
            ->orderBy('name')
            ->get();

        if ($entities->isEmpty()) {
            return Response::text('Aucune entité active.');
        }

        $lines = $entities->map(function (Entity $entity): string {
            $openTasksCount = $entity->tasks()->open()->count();
            $nextDue = $entity->tasks()->open()->whereNotNull('due_date')->orderBy('due_date')->value('due_date');
            $due = $nextDue ? " prochaine échéance {$nextDue->translatedFormat('d M')}," : '';
            $relationsCount = $entity->relations->count() + $entity->relatedFrom->count();

            return "{$entity->name} ({$entity->type->value}) :{$due} {$openTasksCount} tâche(s) ouverte(s), ".
                "{$entity->goals->count()} objectif(s) lié(s), {$relationsCount} relation(s)";
        })->implode(' ; ');

        return Response::text($lines);
    }
}
