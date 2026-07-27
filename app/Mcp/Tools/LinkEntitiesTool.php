<?php

namespace App\Mcp\Tools;

use App\Enums\EntityRelationType;
use App\Mcp\Support\NameResolver;
use App\Models\EntityRelation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('link_entities')]
#[Description('Crée une relation typée entre deux entités (ex : une personne employée par une société). Types disponibles : employed_by, subsidiary_of, owner_of, other.')]
class LinkEntitiesTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()->description("Nom approximatif de l'entité sujet de la relation.")->required(),
            'related_entity' => $schema->string()->description("Nom approximatif de l'entité liée.")->required(),
            'relation_type' => $schema->string()->enum(EntityRelationType::class)->description('employed_by, subsidiary_of, owner_of ou other.')->required(),
            'note' => $schema->string()->description('Note libre optionnelle sur la relation.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'entity' => ['required', 'string'],
            'related_entity' => ['required', 'string'],
            'relation_type' => ['required', Rule::enum(EntityRelationType::class)],
            'note' => ['nullable', 'string'],
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

        if ($entity->id === $relatedEntity->id) {
            return Response::error('Une entité ne peut pas être liée à elle-même.');
        }

        $relationType = EntityRelationType::from($validated['relation_type']);

        $relation = EntityRelation::firstOrCreate(
            ['entity_id' => $entity->id, 'related_entity_id' => $relatedEntity->id, 'relation_type' => $relationType],
            ['note' => $validated['note'] ?? null],
        );

        return Response::text("{$entity->name} est {$relationType->label()} {$relatedEntity->name}.");
    }
}
