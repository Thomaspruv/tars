<?php

namespace App\Mcp\Tools;

use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Mcp\Support\NameResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_goal')]
#[Description("Détail d'un objectif par titre approximatif : jalons, tâches ouvertes/faites, dernière activité.")]
class GetGoalTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()->description("Titre approximatif de l'objectif.")->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['goal' => ['required', 'string']]);

        $goal = $this->resolver->disambiguate(
            $this->resolver->goals($validated['goal']),
            fn ($g): string => $g->title,
            "Plusieurs objectifs correspondent à « {$validated['goal']} »",
        );

        if ($goal === null) {
            return Response::error("Aucun objectif ne correspond à « {$validated['goal']} ».");
        }

        $goal->load(['milestones', 'tasks' => fn ($query) => $query->withArchived()]);

        $totalMilestones = $goal->milestones->count();
        $doneMilestones = $goal->milestones->where('status', MilestoneStatus::Done)->count();
        $openTasks = $goal->tasks->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled])->count();
        $doneTasks = $goal->tasks->where('status', TaskStatus::Done)->count();
        $lastActivity = $goal->tasks->max('updated_at');

        $activityLine = $lastActivity ? 'Dernière activité le '.$lastActivity->translatedFormat('d M').'.' : 'Aucune activité récente.';

        return Response::text(
            "{$goal->title} — {$doneMilestones}/{$totalMilestones} jalons, {$openTasks} tâche(s) ouverte(s), {$doneTasks} faite(s). {$activityLine}"
        );
    }
}
