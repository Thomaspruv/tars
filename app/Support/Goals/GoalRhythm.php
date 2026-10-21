<?php

namespace App\Support\Goals;

use App\Enums\MilestoneStatus;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Note;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Le rythme d'un objectif : quand il a bougé pour la dernière fois, et à quelle
 * cadence.
 *
 * C'est la seule information secondaire que porte une carte d'objectif. Un
 * objectif ne se juge pas à un pourcentage — il se juge à « est-ce que ça
 * avance encore ». D'où une phrase (« a bougé lundi », « 3 sem. sans
 * mouvement ») plutôt qu'une jauge de plus.
 *
 * « Bouger » veut dire : une tâche cochée, une tâche posée, un jalon atteint,
 * une note écrite. Pas une modification du titre — renommer un objectif n'est
 * pas l'avancer. Sa propre création compte, sans quoi un objectif posé hier
 * serait annoncé comme à l'abandon.
 *
 * Les comptes se font en quatre requêtes pour toute une page d'objectifs
 * (`lastActivityFor`), jamais quatre par objectif.
 */
final class GoalRhythm
{
    /** Au-delà, l'objectif a décroché — et on le dit en français, pas en rouge. */
    public const int IDLE_WEEKS = 3;

    /**
     * La dernière fois que chaque objectif a bougé, par id.
     *
     * @param  Collection<int, Goal>  $goals
     * @return array<int, CarbonInterface>
     */
    public static function lastActivityFor(Collection $goals): array
    {
        $ids = $goals->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $latest = [];

        foreach ($goals as $goal) {
            $latest[$goal->id] = $goal->created_at ?? now();
        }

        $sources = [
            // Archivées comprises : une tâche cochée puis rangée a tout de
            // même fait avancer l'objectif ce jour-là.
            Task::withArchived()->whereIn('goal_id', $ids)->selectRaw('goal_id, max(completed_at) as at')->groupBy('goal_id'),
            Task::withArchived()->whereIn('goal_id', $ids)->selectRaw('goal_id, max(created_at) as at')->groupBy('goal_id'),
            Note::whereIn('goal_id', $ids)->selectRaw('goal_id, max(created_at) as at')->groupBy('goal_id'),
            Milestone::whereIn('goal_id', $ids)->where('status', MilestoneStatus::Done->value)->selectRaw('goal_id, max(updated_at) as at')->groupBy('goal_id'),
        ];

        foreach ($sources as $source) {
            foreach ($source->get() as $row) {
                /** @var object{goal_id: int, at: string|null} $row */
                if ($row->at === null) {
                    continue;
                }

                $at = Carbon::parse($row->at);

                if (! isset($latest[$row->goal_id]) || $at->greaterThan($latest[$row->goal_id])) {
                    $latest[$row->goal_id] = $at;
                }
            }
        }

        return $latest;
    }

    public static function lastActivityAt(Goal $goal): CarbonInterface
    {
        return self::lastActivityFor(new Collection([$goal]))[$goal->id] ?? now();
    }

    public static function isStagnant(CarbonInterface $at): bool
    {
        return $at->lessThan(now()->subWeeks(self::IDLE_WEEKS));
    }

    /**
     * « a bougé hier », « a bougé lundi », « a bougé il y a 9 j » — et, passé
     * trois semaines, le nombre de semaines sans rien, qui est la seule forme
     * que la carte met en avant.
     */
    public static function movedLabel(CarbonInterface $at): string
    {
        $days = (int) $at->startOfDay()->diffInDays(today(), absolute: true);

        if (self::isStagnant($at)) {
            return intdiv($days, 7).' sem. sans mouvement';
        }

        return match (true) {
            $days === 0 => 'a bougé aujourd\'hui',
            $days === 1 => 'a bougé hier',
            // Jusqu'à six jours, le nom du jour se repère mieux qu'un nombre :
            // « a bougé lundi » se situe tout seul dans la semaine.
            $days <= 6 => 'a bougé '.$at->translatedFormat('l'),
            default => 'a bougé il y a '.$days.' j',
        };
    }

    /**
     * Les tâches cochées par semaine, de la plus ancienne à cette semaine.
     *
     * @return list<int>
     */
    public static function weeklyActivity(Goal $goal, int $weeks = 12): array
    {
        $completed = $goal->tasks()
            ->withArchived()
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subWeeks($weeks - 1)->startOfWeek())
            ->pluck('completed_at');

        $activity = [];

        for ($weeksAgo = $weeks - 1; $weeksAgo >= 0; $weeksAgo--) {
            $start = now()->subWeeks($weeksAgo)->startOfWeek();
            $end = $start->copy()->endOfWeek();

            $activity[] = $completed->filter(fn (CarbonInterface $at): bool => $at->between($start, $end))->count();
        }

        return $activity;
    }
}
