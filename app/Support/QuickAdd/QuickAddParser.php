<?php

namespace App\Support\QuickAdd;

use App\Enums\TaskPriority;
use App\Models\Entity;
use App\Models\Goal;
use App\Support\FuzzyMatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class QuickAddParser
{
    public function __construct(private readonly FuzzyMatcher $fuzzyMatcher = new FuzzyMatcher) {}

    private const array WEEKDAYS = [
        'lundi' => Carbon::MONDAY,
        'mardi' => Carbon::TUESDAY,
        'mercredi' => Carbon::WEDNESDAY,
        'jeudi' => Carbon::THURSDAY,
        'vendredi' => Carbon::FRIDAY,
        'samedi' => Carbon::SATURDAY,
        'dimanche' => Carbon::SUNDAY,
    ];

    public function parse(string $input): QuickAddResult
    {
        $tokens = [];
        $remaining = $input;

        $priority = null;

        if (preg_match('/\bp([123])\b/i', $remaining, $matches, PREG_OFFSET_CAPTURE)) {
            $priority = TaskPriority::from('p'.$matches[1][0]);
            $tokens[] = new QuickAddToken('priority', $matches[0][0]);
            $remaining = $this->removeAt($remaining, $matches[0][1], strlen($matches[0][0]));
        }

        $entity = null;

        if (preg_match('/@([\p{L}0-9\'-]+)/u', $remaining, $matches, PREG_OFFSET_CAPTURE)) {
            $entity = $this->fuzzyFindEntity($matches[1][0]);
            $tokens[] = new QuickAddToken('entity', $matches[0][0]);
            $remaining = $this->removeAt($remaining, $matches[0][1], strlen($matches[0][0]));
        }

        $goal = null;

        if (preg_match('/#([\p{L}0-9\'-]+)/u', $remaining, $matches, PREG_OFFSET_CAPTURE)) {
            $goal = $this->fuzzyFindGoal($matches[1][0]);
            $tokens[] = new QuickAddToken('goal', $matches[0][0]);
            $remaining = $this->removeAt($remaining, $matches[0][1], strlen($matches[0][0]));
        }

        [$date, $remaining, $dateToken] = $this->extractDate($remaining);

        if ($dateToken !== null) {
            $tokens[] = new QuickAddToken('date', $dateToken);
        }

        $title = trim((string) preg_replace('/\s+/', ' ', $remaining));

        return new QuickAddResult($title, $date, $entity, $goal, $priority, $tokens);
    }

    private function removeAt(string $text, int $offset, int $length): string
    {
        return substr($text, 0, $offset).substr($text, $offset + $length);
    }

    /**
     * @return array{0: ?CarbonInterface, 1: string, 2: ?string}
     */
    private function extractDate(string $text): array
    {
        $fixedPatterns = [
            '/après[- ]demain/iu' => fn (): CarbonInterface => today()->addDays(2),
            '/aujourd\'?hui/iu' => fn (): CarbonInterface => today(),
            '/demain/iu' => fn (): CarbonInterface => today()->addDay(),
        ];

        foreach ($fixedPatterns as $pattern => $resolver) {
            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                $raw = $matches[0][0];

                return [$resolver(), $this->removeAt($text, $matches[0][1], strlen($raw)), $raw];
            }
        }

        $weekdayPattern = '/\b('.implode('|', array_keys(self::WEEKDAYS)).')(\s+prochain)?\b/iu';

        if (preg_match($weekdayPattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $date = $this->nextWeekday(self::WEEKDAYS[Str::lower($matches[1][0])]);

            if (! empty($matches[2][0])) {
                $date = $date->addWeek();
            }

            $raw = $matches[0][0];

            return [$date, $this->removeAt($text, $matches[0][1], strlen($raw)), $raw];
        }

        if (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            $day = (int) $matches[1][0];
            $month = (int) $matches[2][0];
            $year = isset($matches[3][0]) ? (int) $matches[3][0] : now()->year;

            if ($year < 100) {
                $year += 2000;
            }

            try {
                $date = Carbon::createFromDate($year, $month, $day)->startOfDay();
            } catch (\Exception) {
                return [null, $text, null];
            }

            $raw = $matches[0][0];

            return [$date, $this->removeAt($text, $matches[0][1], strlen($raw)), $raw];
        }

        return [null, $text, null];
    }

    private function nextWeekday(int $carbonWeekdayConstant): CarbonInterface
    {
        $date = today();

        for ($i = 0; $i < 7; $i++) {
            if ((int) $date->dayOfWeek === $carbonWeekdayConstant) {
                return $date;
            }

            $date = $date->copy()->addDay();
        }

        return $date;
    }

    private function fuzzyFindEntity(string $needle): ?Entity
    {
        return $this->fuzzyMatcher->bestMatch(Entity::all(), $needle, fn (Entity $entity): string => $entity->name);
    }

    private function fuzzyFindGoal(string $needle): ?Goal
    {
        $slug = Str::slug($needle);
        $bySlug = Goal::all()->first(fn (Goal $goal): bool => $goal->tag === $slug);

        if ($bySlug) {
            return $bySlug;
        }

        return $this->fuzzyMatcher->bestMatch(Goal::all(), $needle, fn (Goal $goal): string => $goal->title);
    }
}
