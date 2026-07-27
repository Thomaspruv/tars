<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_entity')]
#[Description('Supprime définitivement une entité résolue par nom approximatif. Les tâches/objectifs/notes liés sont détachés, pas supprimés.')]
class DeleteEntityTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()->description("Nom approximatif de l'entité à supprimer.")->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['entity' => ['required', 'string']]);

        $entity = $this->resolver->disambiguate(
            $this->resolver->entities($validated['entity']),
            fn ($e): string => $e->name,
            "Plusieurs entités correspondent à « {$validated['entity']} »",
        );

        if ($entity === null) {
            return Response::error("Aucune entité ne correspond à « {$validated['entity']} ».");
        }

        $name = $entity->name;
        $openTasksCount = $entity->tasks()->open()->count();

        $entity->delete();

        $suffix = $openTasksCount > 0 ? " {$openTasksCount} tâche(s) ouverte(s) ont été détachée(s)." : '';

        return Response::text("Entité « {$name} » supprimée.{$suffix}");
    }
}
