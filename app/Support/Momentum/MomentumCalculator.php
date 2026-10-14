<?php

namespace App\Support\Momentum;

use App\Enums\GoalStatus;
use App\Models\Goal;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * La mécanique de jeu de la direction « Niveaux · Tenue » : une jauge par
 * objectif, et une série de jours consécutifs.
 *
 * Rien ici n'est stocké — tout se recalcule depuis les `completed_at` des
 * tâches, pour qu'il n'y ait aucun état de gamification à maintenir en base
 * (et donc rien à réparer quand une tâche est décochée ou supprimée).
 */
final class MomentumCalculator
{
    /** Nombre de semaines d'historique prises en compte par une jauge. */
    private const int WINDOW_WEEKS = 12;

    /** Points perdus par semaine *sans activité*, comme annoncé dans Réglages. */
    private const int DECAY_PER_IDLE_WEEK = 8;

    /** Points gagnés par tâche terminée. */
    private const int GAIN_PER_TASK = 14;

    /** Plancher de la jauge : elle ne tombe jamais à zéro. */
    private const int FLOOR = 10;

    /** Paliers de la série, comme annoncé dans Réglages. */
    private const array STREAK_TIERS = [7, 30, 100];

    /**
     * Jours consécutifs avec au moins une tâche cochée.
     *
     * On repart d'hier quand rien n'a encore été fait aujourd'hui : une série
     * ne casse qu'après une journée entière vide, pas dès le réveil.
     */
    public function streakDays(): int
    {
        /** @var list<string> $days */
        $days = Task::whereNotNull('completed_at')
            ->where('completed_at', '>=', today()->subDays(365))
            ->reorder()
            ->pluck('completed_at')
            ->map(fn (CarbonInterface $date): string => $date->toDateString())
            ->unique()
            ->values()
            ->all();

        $active = array_flip($days);

        $cursor = today();

        if (! isset($active[$cursor->toDateString()])) {
            $cursor = $cursor->subDay();
        }

        $streak = 0;

        while (isset($active[$cursor->toDateString()])) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    /**
     * Le prochain palier à atteindre, ou null quand ils sont tous passés.
     */
    public function nextStreakTier(?int $streak = null): ?int
    {
        $streak ??= $this->streakDays();

        foreach (self::STREAK_TIERS as $tier) {
            if ($streak < $tier) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * La jauge d'un objectif, entre {@see self::FLOOR} et 100.
     *
     * Semaine par semaine sur la fenêtre : une semaine vide fait perdre
     * {@see self::DECAY_PER_IDLE_WEEK} points, chaque tâche terminée en fait
     * gagner {@see self::GAIN_PER_TASK}.
     *
     * @param  list<string>  $completionDates  dates ISO des tâches terminées de l'objectif
     */
    public function gaugeLevel(array $completionDates): int
    {
        $windowStart = today()->startOfWeek(Carbon::MONDAY)->subWeeks(self::WINDOW_WEEKS - 1);

        $perWeek = array_fill(0, self::WINDOW_WEEKS, 0);

        foreach ($completionDates as $date) {
            $week = (int) floor($windowStart->diffInDays(Carbon::parse($date)->startOfWeek(Carbon::MONDAY), false) / 7);

            if ($week >= 0 && $week < self::WINDOW_WEEKS) {
                $perWeek[$week]++;
            }
        }

        $level = self::FLOOR;

        foreach ($perWeek as $completed) {
            $level = $completed === 0
                ? max(self::FLOOR, $level - self::DECAY_PER_IDLE_WEEK)
                : min(100, $level + self::GAIN_PER_TASK * $completed);
        }

        return $level;
    }

    /**
     * Les trois objectifs de la bande d'état.
     *
     * Règle de sélection : les deux qui vont le mieux, plus celui qui va le
     * moins bien. La bande dit donc toujours à la fois « ça avance ici » et
     * « ça n'avance plus là » — c'est ce dernier qui justifie la bande.
     *
     * @return Collection<int, GaugeReading>
     */
    public function bandGauges(int $limit = 3): Collection
    {
        $goals = Goal::where('status', GoalStatus::Active->value)
            ->with(['tasks' => fn ($query) => $query->whereNotNull('completed_at')->select('id', 'goal_id', 'completed_at')])
            ->get();

        if ($goals->isEmpty()) {
            /** @var Collection<int, GaugeReading> */
            return collect();
        }

        $readings = $goals->map(function (Goal $goal): GaugeReading {
            /** @var list<string> $dates */
            $dates = $goal->tasks->map(fn (Task $task): string => $task->completed_at?->toDateString() ?? '')->filter()->values()->all();

            $last = $goal->tasks->max('completed_at');

            return new GaugeReading(
                goal: $goal,
                level: $this->gaugeLevel($dates),
                // Comparaison de *dates* : une tâche finie il y a quatre semaines
                // pile doit lire « 4 sem. », pas 3, quelle que soit l'heure.
                weeksIdle: $last instanceof CarbonInterface ? (int) floor($last->startOfDay()->diffInDays(today()) / 7) : self::WINDOW_WEEKS,
            );
        })->sortByDesc(fn (GaugeReading $reading): int => $reading->level)->values();

        if ($readings->count() <= $limit) {
            return $readings;
        }

        return $readings->take($limit - 1)
            ->concat($readings->slice(-1))
            ->values();
    }
}
