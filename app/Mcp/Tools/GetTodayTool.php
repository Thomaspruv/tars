<?php

namespace App\Mcp\Tools;

use App\Models\Event;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_today')]
#[Description("La vue Aujourd'hui : tâches du jour et en retard, événements des 7 prochains jours, prochaine revue.")]
class GetTodayTool extends LoggedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request): Response
    {
        $tasks = Task::forToday()->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy('priority')->get();
        $events = Event::whereBetween('starts_at', [today(), today()->addDays(7)->endOfDay()])->orderBy('starts_at')->get();

        $today = today();
        $nextReview = $today->isSunday() && now()->hour < 17 ? $today->copy() : $today->copy()->next(Carbon::SUNDAY);
        $daysUntilReview = (int) $today->diffInDays($nextReview);

        $taskLines = $tasks->isEmpty()
            ? 'Aucune tâche.'
            : $tasks->map(fn (Task $t): string => $t->title.($t->due_date ? ' ('.$t->due_date->translatedFormat('d M').')' : ''))->implode(' ; ');

        $eventLines = $events->isEmpty()
            ? 'Aucun événement à venir.'
            : $events->map(fn (Event $e): string => $e->title.' — '.$e->starts_at->translatedFormat('d M H:i'))->implode(' ; ');

        $reviewLine = $daysUntilReview === 0 ? "La revue est prévue aujourd'hui." : "Prochaine revue dans {$daysUntilReview} jour(s).";

        return Response::text("Tâches : {$taskLines}\nÉvénements : {$eventLines}\n{$reviewLine}");
    }
}
