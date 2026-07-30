<?php

namespace App\Support\Journal;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class JournalDateResolver
{
    private const array MONTHS = [
        'janvier' => 1, 'fevrier' => 2, 'février' => 2, 'mars' => 3, 'avril' => 4,
        'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8, 'août' => 8,
        'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12, 'décembre' => 12,
    ];

    /**
     * Resolves a single natural-language date ("aujourd'hui", "hier", or an
     * explicit date understood by Carbon). Returns null for an empty input —
     * the caller decides what the default means for it.
     *
     * @throws \Exception when $raw isn't empty but isn't a recognizable date
     */
    public function resolveDate(?string $raw): ?CarbonInterface
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim($raw));

        return match (true) {
            str_contains($normalized, 'aujourd') => today(),
            $normalized === 'hier' => today()->subDay(),
            default => Carbon::parse($raw)->startOfDay(),
        };
    }

    /**
     * Resolves a natural-language period ("cette semaine", a French month
     * name) to a start/end date range. Falls back to the last 7 days when
     * $raw is empty or unrecognized.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function resolvePeriod(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [today()->subDays(6), today()];
        }

        $normalized = mb_strtolower(trim($raw));

        if (str_contains($normalized, 'semaine')) {
            return [today()->startOfWeek(), today()->endOfWeek()];
        }

        if (str_contains($normalized, 'aujourd')) {
            return [today(), today()];
        }

        foreach (self::MONTHS as $name => $month) {
            if (str_contains($normalized, $name)) {
                $year = $month > now()->month ? now()->year - 1 : now()->year;
                $start = Carbon::createFromDate($year, $month, 1)->startOfDay();

                return [$start, $start->copy()->endOfMonth()];
            }
        }

        return [today()->subDays(6), today()];
    }
}
