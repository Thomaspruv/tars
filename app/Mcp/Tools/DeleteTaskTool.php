<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_task')]
#[Description('Supprime définitivement une tâche par titre approximatif, faite ou non. Pour marquer une tâche comme faite, utiliser complete_task.')]
class DeleteTaskTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Titre approximatif de la tâche à supprimer.')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['task' => ['required', 'string']]);

        $task = $this->resolver->disambiguate(
            $this->resolver->tasks($validated['task']),
            fn ($t): string => $t->title,
            "Plusieurs tâches correspondent à « {$validated['task']} »",
        );

        if ($task === null) {
            return Response::error("Aucune tâche ne correspond à « {$validated['task']} ».");
        }

        $title = $task->title;
        $task->delete();

        return Response::text("Tâche « {$title} » supprimée.");
    }
}
