<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_tasks')]
#[Description("Liste les tâches ouvertes, filtrables par période (aujourd'hui, semaine, retard) et/ou par objectif/entité (noms approximatifs).")]
class ListTasksTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()->enum(['today', 'week', 'overdue'])->description('Période : today, week ou overdue.'),
            'entity' => $schema->string()->description("Nom approximatif de l'entité."),
            'goal' => $schema->string()->description("Nom approximatif de l'objectif."),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'filter' => ['nullable', 'in:today,week,overdue'],
            'entity' => ['nullable', 'string'],
            'goal' => ['nullable', 'string'],
        ]);

        $query = Task::open()->with(['goal', 'entity']);

        match ($validated['filter'] ?? null) {
            'today' => $query->whereDate('scheduled_date', today()),
            'week' => $query->whereBetween('scheduled_date', [today(), today()->addDays(7)]),
            'overdue' => $query->whereDate('due_date', '<', today()),
            default => null,
        };

        if (! empty($validated['entity'])) {
            $entity = $this->resolver->disambiguate(
                $this->resolver->entities($validated['entity']),
                fn ($e): string => $e->name,
                "Plusieurs entités correspondent à « {$validated['entity']} »",
            );

            if ($entity === null) {
                return Response::error("Aucune entité ne correspond à « {$validated['entity']} ».");
            }

            $query->where('entity_id', $entity->id);
        }

        if (! empty($validated['goal'])) {
            $goal = $this->resolver->disambiguate(
                $this->resolver->goals($validated['goal']),
                fn ($g): string => $g->title,
                "Plusieurs objectifs correspondent à « {$validated['goal']} »",
            );

            if ($goal === null) {
                return Response::error("Aucun objectif ne correspond à « {$validated['goal']} ».");
            }

            $query->where('goal_id', $goal->id);
        }

        $tasks = $query->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('priority')->get();

        if ($tasks->isEmpty()) {
            return Response::text('Aucune tâche.');
        }

        $lines = $tasks->take(20)->map(function (Task $task): string {
            $due = $task->due_date?->translatedFormat('d M') ?? $task->scheduled_date?->translatedFormat('d M');
            $priority = $task->priority?->value;

            return trim("{$task->title}".($due ? " — {$due}" : '').($priority ? " ({$priority})" : ''));
        })->implode(' ; ');

        $suffix = $tasks->count() > 20 ? ' (+'.($tasks->count() - 20).' autres)' : '';

        return Response::text($lines.$suffix);
    }
}
