<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Mcp\Support\AmbiguousToolCall;
use App\Mcp\Support\NameResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('complete_task')]
#[Description('Marque une tâche ouverte comme faite, par titre approximatif. Si la tâche est récurrente, elle avance simplement à sa prochaine échéance au lieu de disparaître.')]
class CompleteTaskTool extends Tool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Titre approximatif de la tâche à compléter.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->completeTask($request);
        } catch (AmbiguousToolCall $e) {
            return Response::text($e->getMessage());
        }
    }

    private function completeTask(Request $request): Response
    {
        $validated = $request->validate(['task' => ['required', 'string']]);

        $task = $this->resolver->disambiguate(
            $this->resolver->openTasks($validated['task']),
            fn ($t): string => $t->title,
            "Plusieurs tâches correspondent à « {$validated['task']} »",
        );

        if ($task === null) {
            return Response::error("Aucune tâche ouverte ne correspond à « {$validated['task']} ».");
        }

        $title = $task->title;
        $task->toggleCompletion();
        $task->refresh();

        if ($task->status === TaskStatus::Done) {
            return Response::text("Tâche « {$title} » marquée faite.");
        }

        $next = $task->due_date ?? $task->scheduled_date;

        return Response::text("Tâche récurrente « {$title} » reportée au ".($next?->translatedFormat('d M') ?? 'prochain cycle').'.');
    }
}
