<?php

namespace App\Mcp\Tools;

use App\Enums\TaskPriority;
use App\Mcp\Support\NameResolver;
use App\Models\Task;
use App\Support\QuickAdd\QuickAddParser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_task')]
#[Description("Crée une tâche. La date accepte le langage naturel français (demain, vendredi, 25/12). L'entité et l'objectif sont résolus par nom approximatif, jamais par identifiant.")]
class AddTaskTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Le titre de la tâche.')->required(),
            'date' => $schema->string()->description('Date en français : "demain", "vendredi", "25/12"...'),
            'entity' => $schema->string()->description("Nom approximatif de l'entité concernée."),
            'goal' => $schema->string()->description("Nom approximatif de l'objectif concerné."),
            'priority' => $schema->string()->enum(TaskPriority::class)->description('Priorité : p1, p2 ou p3.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'title' => ['required', 'string'],
            'date' => ['nullable', 'string'],
            'entity' => ['nullable', 'string'],
            'goal' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
        ]);

        $entity = null;

        if (! empty($validated['entity'])) {
            $entity = $this->resolver->disambiguate(
                $this->resolver->entities($validated['entity']),
                fn ($e): string => $e->name,
                "Plusieurs entités correspondent à « {$validated['entity']} »",
            );

            if ($entity === null) {
                return Response::error("Aucune entité ne correspond à « {$validated['entity']} ».");
            }
        }

        $goal = null;

        if (! empty($validated['goal'])) {
            $goal = $this->resolver->disambiguate(
                $this->resolver->goals($validated['goal']),
                fn ($g): string => $g->title,
                "Plusieurs objectifs correspondent à « {$validated['goal']} »",
            );

            if ($goal === null) {
                return Response::error("Aucun objectif ne correspond à « {$validated['goal']} ».");
            }
        }

        $date = ! empty($validated['date']) ? (new QuickAddParser)->parse($validated['date'])->date : null;

        $task = Task::create([
            'title' => $validated['title'],
            'scheduled_date' => $date,
            'entity_id' => $entity?->id,
            'goal_id' => $goal?->id,
            'priority' => $validated['priority'] ?? null,
            'status' => 'todo',
        ]);

        $suffix = $date ? ' Prévue le '.$date->translatedFormat('d M').'.' : '';

        return Response::text("Tâche créée : {$task->title}.{$suffix}");
    }
}
