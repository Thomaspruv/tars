<?php

namespace App\Support\Review;

use App\Enums\GoalStatus;
use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\BrainDocument;
use App\Models\Decision;
use App\Models\Event;
use App\Models\Goal;
use App\Models\JournalEntry;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireRun;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class ReviewContextBuilder
{
    public function build(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        return implode("\n\n", array_filter([
            $this->buildStats($periodStart, $periodEnd),
            $this->buildActiveGoals(),
            $this->buildUpcomingDeadlines(),
            $this->buildPastDecisions(),
            $this->buildRecentBrainNotes($periodStart, $periodEnd),
            $this->buildJournalSummary($periodStart, $periodEnd),
            $this->buildLastQuestionnaireRun(),
            $this->buildProfile(),
        ]));
    }

    private function buildStats(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $done = Task::where('status', TaskStatus::Done)
            ->whereBetween('completed_at', [$periodStart, $periodEnd])
            ->count();

        $planned = Task::where(function ($query) use ($periodStart, $periodEnd): void {
            $query->whereBetween('scheduled_date', [$periodStart, $periodEnd])
                ->orWhereBetween('due_date', [$periodStart, $periodEnd]);
        })->count();

        $openCount = Task::open()->topLevel()->count();
        $orphanCount = Task::open()->topLevel()->orphan()->count();
        $orphanRatio = $openCount > 0 ? round(($orphanCount / $openCount) * 100) : 0;

        return <<<MD
        ## Statistiques de la période
        - Tâches terminées : {$done}
        - Tâches planifiées (échéance ou date planifiée sur la période) : {$planned}
        - Tâches orphelines (sans objectif) : {$orphanCount}/{$openCount} tâches ouvertes ({$orphanRatio}%)
        MD;
    }

    private function buildActiveGoals(): string
    {
        $goals = Goal::where('status', GoalStatus::Active)
            ->with(['milestones', 'tasks', 'notes'])
            ->get();

        if ($goals->isEmpty()) {
            return "## Objectifs actifs\nAucun objectif actif.";
        }

        $lines = $goals->map(function (Goal $goal): string {
            $milestonesDone = $goal->milestones->where('status', MilestoneStatus::Done)->count();
            $milestonesTotal = $goal->milestones->count();

            $lastActivity = collect([
                $goal->tasks->max('updated_at'),
                $goal->notes->max('created_at'),
                $goal->created_at,
            ])->filter()->max();

            $weeksSinceActivity = $lastActivity ? $lastActivity->diffInWeeks(now()) : null;

            $target = $goal->target_date ? $goal->target_date->translatedFormat('d M Y') : 'aucune';

            return "- **{$goal->title}** — jalons {$milestonesDone}/{$milestonesTotal}, cible : {$target}, dernière activité il y a {$weeksSinceActivity} semaine(s)";
        })->implode("\n");

        return "## Objectifs actifs\n{$lines}";
    }

    private function buildUpcomingDeadlines(): string
    {
        $until = today()->addDays(14);

        $tasks = Task::open()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [today(), $until])
            ->with('goal')
            ->orderBy('due_date')
            ->get()
            ->map(fn (Task $task): string => "- Tâche : {$task->title} — {$task->due_date->translatedFormat('d M')}".($task->goal ? " (#{$task->goal->title})" : ''));

        $events = Event::whereBetween('starts_at', [today(), $until->copy()->endOfDay()])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Event $event): string => "- Événement : {$event->title} — {$event->starts_at->translatedFormat('d M')}");

        $lines = $tasks->merge($events);

        if ($lines->isEmpty()) {
            return "## Échéances à venir (J+14)\nAucune échéance dans les 14 prochains jours.";
        }

        return "## Échéances à venir (J+14)\n".$lines->implode("\n");
    }

    private function buildPastDecisions(): string
    {
        $decisions = Decision::where('decided_at', '>=', now()->subWeeks(6))
            ->orderByDesc('decided_at')
            ->get();

        if ($decisions->isEmpty()) {
            return "## Décisions récentes (6 dernières semaines)\nAucune décision récente.";
        }

        $lines = $decisions->map(fn (Decision $decision): string => "- {$decision->decided_at->translatedFormat('d M')} : {$decision->content}".($decision->context ? " — {$decision->context}" : ''))->implode("\n");

        return "## Décisions récentes (6 dernières semaines)\n{$lines}";
    }

    private function buildRecentBrainNotes(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $documents = BrainDocument::where('path', 'not like', 'Profil/%')
            ->where(function ($query) use ($periodStart, $periodEnd): void {
                $query->whereBetween('mtime', [$periodStart, $periodEnd])
                    ->orWhereBetween('created_at', [$periodStart, $periodEnd]);
            })
            ->with(['goals', 'entities'])
            ->get();

        if ($documents->isEmpty()) {
            return "## Notes récentes du cerveau\nAucune note récente.";
        }

        $lines = $documents->map(function (BrainDocument $document): string {
            $anchors = $document->goals->pluck('title')->merge($document->entities->pluck('name'))->implode(', ');
            $excerpt = Str::limit(strip_tags((string) $document->content), 200);

            return "- **{$document->title}** ({$document->path})".($anchors ? " [{$anchors}]" : '')."\n  {$excerpt}";
        })->implode("\n");

        return "## Notes récentes du cerveau\n{$lines}";
    }

    private function buildJournalSummary(CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $entries = JournalEntry::whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->orderBy('date')
            ->get();

        if ($entries->isEmpty()) {
            return "## Journal de la période\nAucune entrée de journal sur cette période.";
        }

        $moods = $entries->pluck('mood')->filter();
        $moodLine = $moods->isNotEmpty()
            ? 'Mood moyen : '.round($moods->avg(), 1)."/10 sur {$entries->count()} jour(s) rempli(s)."
            : "{$entries->count()} jour(s) rempli(s), mood non renseigné.";

        $excerpts = $entries->map(function (JournalEntry $entry): string {
            $moodPart = $entry->mood !== null ? " (mood {$entry->mood}/10)" : '';

            return "- {$entry->date->translatedFormat('d M')}{$moodPart} : ".Str::limit($entry->summary, 150);
        })->implode("\n");

        return "## Journal de la période\n{$moodLine}\n{$excerpts}";
    }

    private function buildLastQuestionnaireRun(): string
    {
        $run = QuestionnaireRun::where('status', 'completed')
            ->with(['questionnaire', 'answers'])
            ->orderByDesc('completed_at')
            ->first();

        if (! $run) {
            return "## Dernier bilan complété\nAucun bilan complété pour l'instant.";
        }

        $answers = $run->answers->map(function (QuestionnaireAnswer $answer): string {
            $value = $answer->answer_numeric !== null ? "{$answer->answer_numeric}/10" : $answer->answer_text;

            return "- {$answer->question_text} : {$value}";
        })->implode("\n");

        return "## Dernier bilan complété\n{$run->questionnaire->name} ({$run->completed_at->translatedFormat('d M Y')})\n{$answers}";
    }

    private function buildProfile(): string
    {
        $documents = BrainDocument::where('path', 'like', 'Profil/%')->get();

        if ($documents->isEmpty()) {
            return "## Profil\nAucun document de profil.";
        }

        $lines = $documents->map(fn (BrainDocument $document): string => "### {$document->title}\n{$document->content}")->implode("\n\n");

        return "## Profil\n{$lines}";
    }
}
