<?php

namespace App\Mcp\Tools;

use App\Enums\MilestoneStatus;
use App\Models\Goal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_goals')]
#[Description('Liste les objectifs actifs avec leur avancement en jalons.')]
class ListGoalsTool extends LoggedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request): Response
    {
        $goals = Goal::where('status', 'active')->with('milestones')->orderBy('position')->get();

        if ($goals->isEmpty()) {
            return Response::text('Aucun objectif actif.');
        }

        $lines = $goals->map(function (Goal $goal): string {
            $total = $goal->milestones->count();
            $done = $goal->milestones->where('status', MilestoneStatus::Done)->count();
            $progress = $total > 0 ? " ({$done}/{$total} jalons)" : '';

            return "{$goal->title}{$progress}";
        })->implode(' ; ');

        return Response::text($lines);
    }
}
