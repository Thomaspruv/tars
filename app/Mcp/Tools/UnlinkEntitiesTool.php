<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Models\EntityRelation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('unlink_entities')]
#[Description('Supprime la ou les relations existant entre deux entités, quel que soit le sens.')]
class UnlinkEntitiesTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()->description("Nom approximatif d'une des deux entités.")->required(),
            'related_entity' => $schema->string()->description("Nom approximatif de l'autre entité.")->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'entity' => ['required', 'string'],
            'related_entity' => ['required', 'string'],
        ]);

        $entity = $this->resolver->disambiguate(
            $this->resolver->entities($validated['entity']),
            fn ($e): string => $e->name,
            "Plusieurs entités correspondent à « {$validated['entity']} »",
        );

        if ($entity === null) {
            return Response::error("Aucune entité ne correspond à « {$validated['entity']} ».");
        }

        $relatedEntity = $this->resolver->disambiguate(
            $this->resolver->entities($validated['related_entity']),
            fn ($e): string => $e->name,
            "Plusieurs entités correspondent à « {$validated['related_entity']} »",
        );

        if ($relatedEntity === null) {
            return Response::error("Aucune entité ne correspond à « {$validated['related_entity']} ».");
        }

        $deleted = EntityRelation::query()
            ->where(function ($query) use ($entity, $relatedEntity): void {
                $query->where('entity_id', $entity->id)->where('related_entity_id', $relatedEntity->id);
            })
            ->orWhere(function ($query) use ($entity, $relatedEntity): void {
                $query->where('entity_id', $relatedEntity->id)->where('related_entity_id', $entity->id);
            })
            ->delete();

        if ($deleted === 0) {
            return Response::error("Aucune relation entre {$entity->name} et {$relatedEntity->name}.");
        }

        return Response::text("Relation entre {$entity->name} et {$relatedEntity->name} supprimée.");
    }
}
