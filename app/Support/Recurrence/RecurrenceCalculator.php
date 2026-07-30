<?php

namespace App\Support\Recurrence;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecurrenceCalculator
{
    private const array WEEKDAYS = [
        'mon' => CarbonInterface::MONDAY,
        'tue' => CarbonInterface::TUESDAY,
        'wed' => CarbonInterface::WEDNESDAY,
        'thu' => CarbonInterface::THURSDAY,
        'fri' => CarbonInterface::FRIDAY,
        'sat' => CarbonInterface::SATURDAY,
        'sun' => CarbonInterface::SUNDAY,
    ];

    public function nextOccurrence(string $pattern, CarbonInterface $from): CarbonInterface
    {
        [$frequency, $argument] = str_contains($pattern, ':')
            ? explode(':', $pattern, 2)
            : [$pattern, null];

        return match ($frequency) {
            'daily' => $from->copy()->addDay(),
            'weekly' => $argument !== null
                ? $this->nextWeekday($from, self::WEEKDAYS[Str::lower($argument)] ?? throw new InvalidArgumentException("Unknown weekday [{$argument}]."))
                : $from->copy()->addWeek(),
            'monthly' => $argument !== null
                ? $from->copy()->addMonthNoOverflow()->day(min((int) $argument, $from->copy()->addMonthNoOverflow()->daysInMonth))
                : $from->copy()->addMonthNoOverflow(),
            'quarterly' => $argument !== null
                ? $from->copy()->addMonthsNoOverflow(3)->day(min((int) $argument, $from->copy()->addMonthsNoOverflow(3)->daysInMonth))
                : $from->copy()->addMonthsNoOverflow(3),
            'yearly' => $argument !== null
                ? $this->nextYearlyDate($from, $argument)
                : $from->copy()->addYearNoOverflow(),
            default => throw new InvalidArgumentException("Unknown recurrence pattern [{$pattern}]."),
        };
    }

    /**
     * Unlike nextOccurrence(), which always assumes $from IS a previous
     * occurrence and unconditionally advances past it, this returns the
     * anchor date landing in $from's own cycle when that date hasn't
     * passed yet — used to compute a schedule's FIRST occurrence from an
     * arbitrary reference point (e.g. a questionnaire's creation date)
     * rather than from a known prior occurrence.
     */
    public function firstOccurrenceOnOrAfter(string $pattern, CarbonInterface $from): CarbonInterface
    {
        $candidate = $this->nextOccurrence($pattern, $this->shiftBackOnePeriod($pattern, $from));

        return $candidate->greaterThanOrEqualTo($from) ? $candidate : $this->nextOccurrence($pattern, $from);
    }

    private function shiftBackOnePeriod(string $pattern, CarbonInterface $from): CarbonInterface
    {
        $frequency = str_contains($pattern, ':') ? explode(':', $pattern, 2)[0] : $pattern;

        return match ($frequency) {
            'daily' => $from->copy()->subDay(),
            'weekly' => $from->copy()->subWeek(),
            'monthly' => $from->copy()->subMonthNoOverflow(),
            'quarterly' => $from->copy()->subMonthsNoOverflow(3),
            'yearly' => $from->copy()->subYearNoOverflow(),
            default => throw new InvalidArgumentException("Unknown recurrence pattern [{$pattern}]."),
        };
    }

    private function nextWeekday(CarbonInterface $from, int $targetWeekday): CarbonInterface
    {
        $date = $from->copy()->addDay();

        while ((int) $date->dayOfWeek !== $targetWeekday) {
            $date = $date->addDay();
        }

        return $date;
    }

    private function nextYearlyDate(CarbonInterface $from, string $monthDay): CarbonInterface
    {
        [$month, $day] = array_map('intval', explode('-', $monthDay));

        $next = $from->copy()->addYearNoOverflow()->month($month)->day(1);

        return $next->day(min($day, $next->daysInMonth));
    }
}
