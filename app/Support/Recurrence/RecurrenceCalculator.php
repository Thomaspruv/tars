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
            'yearly' => $argument !== null
                ? $this->nextYearlyDate($from, $argument)
                : $from->copy()->addYearNoOverflow(),
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
