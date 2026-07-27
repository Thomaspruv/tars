<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('assign_task')]
#[Description('(Ré)assigne une tâche ouverte existante à une entité, tous deux résolus par nom approximatif.')]
class AssignTaskTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Titre approximatif de la tâche.')->required(),
            'entity' => $schema->string()->description("Nom approximatif de l'entité à laquelle assigner la tâche.")->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'task' => ['required', 'string'],
            'entity' => ['required', 'string'],
        ]);

        $task = $this->resolver->disambiguate(
            $this->resolver->openTasks($validated['task']),
            fn ($t): string => $t->title,
            "Plusieurs tâches correspondent à « {$validated['task']} »",
        );

        if ($task === null) {
            return Response::error("Aucune tâche ouverte ne correspond à « {$validated['task']} ».");
        }

        $entity = $this->resolver->disambiguate(
            $this->resolver->entities($validated['entity']),
            fn ($e): string => $e->name,
            "Plusieurs entités correspondent à « {$validated['entity']} »",
        );

        if ($entity === null) {
            return Response::error("Aucune entité ne correspond à « {$validated['entity']} ».");
        }

        $task->update(['entity_id' => $entity->id]);

        return Response::text("Tâche « {$task->title} » assignée à {$entity->name}.");
    }
}
