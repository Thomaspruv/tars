<?php

namespace App\Mcp\Tools;

use App\Models\Entity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_entities')]
#[Description('Liste les entités actives (entreprises, biens...) avec leur type.')]
class ListEntitiesTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $entities = Entity::where('status', 'active')->orderBy('name')->get();

        if ($entities->isEmpty()) {
            return Response::text('Aucune entité active.');
        }

        $lines = $entities->map(fn (Entity $entity): string => "{$entity->name} ({$entity->type->value})")->implode(' ; ');

        return Response::text($lines);
    }
}
