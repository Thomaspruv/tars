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

    /** Le code attendu par RecurrenceCalculator pour `weekly:xxx`. */
    private const array WEEKDAY_CODES = [
        'lundi' => 'mon',
        'mardi' => 'tue',
        'mercredi' => 'wed',
        'jeudi' => 'thu',
        'vendredi' => 'fri',
        'samedi' => 'sat',
        'dimanche' => 'sun',
    ];

    public function parse(string $input): QuickAddResult
    {
        $remaining = $input;

        [$priority, $remaining, $priorityToken] = $this->extractPriority($remaining);
        [$entity, $remaining, $entityToken] = $this->extractEntity($remaining);
        [$goal, $remaining, $goalToken] = $this->extractGoal($remaining);

        // La récurrence passe avant la date : « tous les lundis » contient
        // « lundi », que l'extracteur de date prendrait pour une échéance.
        [$recurrence, $remaining, $recurrenceToken] = $this->extractRecurrence($remaining);
        [$time, $remaining, $timeToken] = $this->extractTime($remaining);
        [$date, $remaining, $dateToken] = $this->extractDate($remaining);

        $title = trim((string) preg_replace('/\s+/', ' ', $remaining));

        // Les puces se lisent dans l'ordre de l'aperçu — quand · récurrence ·
        // rattachements · priorité — pas dans l'ordre où le parseur a mordu.
        $tokens = array_values(array_filter(
            [$dateToken, $timeToken, $recurrenceToken, $entityToken, $goalToken, $priorityToken],
            fn (?QuickAddToken $token): bool => $token !== null,
        ));

        return new QuickAddResult($title, $date, $entity, $goal, $priority, $tokens, $time, $recurrence);
    }

    private function removeAt(string $text, int $offset, int $length): string
    {
        return substr($text, 0, $offset).substr($text, $offset + $length);
    }

    /**
     * @return array{0: ?TaskPriority, 1: string, 2: ?QuickAddToken}
     */
    private function extractPriority(string $text): array
    {
        if (! preg_match('/\bp([123])\b/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [null, $text, null];
        }

        $raw = $matches[0][0];

        return [
            TaskPriority::from('p'.$matches[1][0]),
            $this->removeAt($text, $matches[0][1], strlen($raw)),
            new QuickAddToken('priority', $raw, Str::upper($raw)),
        ];
    }

    /**
     * @return array{0: ?Entity, 1: string, 2: ?QuickAddToken}
     */
    private function extractEntity(string $text): array
    {
        $reference = $this->extractReference($text, '@');

        if ($reference === null) {
            return [null, $text, null];
        }

        [$needle, $raw, $offset] = $reference;
        $entity = $this->fuzzyFindEntity($needle);

        // Un nom propre en deux mots (« @SARL Alpha ») ne doit laisser aucun
        // morceau dans le titre : s'il ne résout pas en entier, on retombe sur
        // le premier mot seul et on ne consomme que lui.
        if ($entity === null && str_contains($needle, ' ')) {
            $firstWord = Str::before($needle, ' ');
            $entity = $this->fuzzyFindEntity($firstWord);
            $needle = $firstWord;
            $raw = '@'.$firstWord;
        }

        return [
            $entity,
            $this->removeAt($text, $offset, strlen($raw)),
            new QuickAddToken('entity', $raw, $entity->name ?? $needle),
        ];
    }

    /**
     * @return array{0: ?Goal, 1: string, 2: ?QuickAddToken}
     */
    private function extractGoal(string $text): array
    {
        $reference = $this->extractReference($text, '#');

        if ($reference === null) {
            return [null, $text, null];
        }

        [$needle, $raw, $offset] = $reference;
        $goal = $this->fuzzyFindGoal($needle);

        if ($goal === null && str_contains($needle, ' ')) {
            $firstWord = Str::before($needle, ' ');
            $goal = $this->fuzzyFindGoal($firstWord);
            $needle = $firstWord;
            $raw = '#'.$firstWord;
        }

        return [
            $goal,
            $this->removeAt($text, $offset, strlen($raw)),
            new QuickAddToken('goal', $raw, $goal->title ?? $needle),
        ];
    }

    /**
     * Un `@` ou un `#` suivi d'un mot, et des mots capitalisés qui le suivent :
     * « @SARL Alpha » désigne une entité, pas l'entité « SARL » dans un titre
     * qui garderait « Alpha ».
     *
     * @return array{0: string, 1: string, 2: int}|null [besoin, texte brut, offset]
     */
    private function extractReference(string $text, string $marker): ?array
    {
        $pattern = '/'.preg_quote($marker, '/').'([\p{L}0-9\'-]+(?:\s+\p{Lu}[\p{L}0-9\'-]*)*)/u';

        if (! preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [$matches[1][0], $matches[0][0], $matches[0][1]];
    }

    /**
     * @return array{0: ?string, 1: string, 2: ?QuickAddToken}
     */
    private function extractRecurrence(string $text): array
    {
        $weekdayPattern = '/\b(?:tous\s+les|chaque)\s+('.implode('|', array_keys(self::WEEKDAY_CODES)).')s?\b/iu';

        if (preg_match($weekdayPattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $raw = $matches[0][0];
            $day = Str::lower($matches[1][0]);

            return [
                'weekly:'.self::WEEKDAY_CODES[$day],
                $this->removeAt($text, $matches[0][1], strlen($raw)),
                new QuickAddToken('recurrence', $raw, 'tous les '.$day.'s'),
            ];
        }

        $patterns = [
            '/\b(?:tous\s+les\s+jours|chaque\s+jour|quotidien(?:ne)?)\b/iu' => ['daily', 'tous les jours'],
            '/\b(?:toutes\s+les\s+semaines|chaque\s+semaine|hebdomadaire)\b/iu' => ['weekly', 'toutes les semaines'],
            '/\b(?:tous\s+les\s+mois|chaque\s+mois|mensuel(?:le)?)\b/iu' => ['monthly', 'tous les mois'],
            '/\b(?:tous\s+les\s+ans|chaque\s+(?:ann[ée]e|an)|annuel(?:le)?)\b/iu' => ['yearly', 'tous les ans'],
        ];

        foreach ($patterns as $pattern => [$recurrence, $label]) {
            if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                $raw = $matches[0][0];

                return [
                    $recurrence,
                    $this->removeAt($text, $matches[0][1], strlen($raw)),
                    new QuickAddToken('recurrence', $raw, $label),
                ];
            }
        }

        return [null, $text, null];
    }

    /**
     * @return array{0: ?string, 1: string, 2: ?QuickAddToken}
     */
    private function extractTime(string $text): array
    {
        if (! preg_match('/\b(?:à\s+)?(\d{1,2})\s*h(?:\s*(\d{2}))?\b/iu', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [null, $text, null];
        }

        $hour = (int) $matches[1][0];
        $minute = isset($matches[2]) ? (int) $matches[2][0] : 0;

        if ($hour > 23 || $minute > 59) {
            return [null, $text, null];
        }

        $raw = $matches[0][0];
        $label = $minute === 0 ? $hour.'h' : $hour.'h'.sprintf('%02d', $minute);

        return [
            sprintf('%02d:%02d', $hour, $minute),
            $this->removeAt($text, $matches[0][1], strlen($raw)),
            new QuickAddToken('time', $raw, $label),
        ];
    }

    /**
     * @return array{0: ?CarbonInterface, 1: string, 2: ?QuickAddToken}
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
                $date = $resolver();

                return [$date, $this->removeAt($text, $matches[0][1], strlen($raw)), $this->dateToken($date, $raw)];
            }
        }

        $weekdayPattern = '/\b('.implode('|', array_keys(self::WEEKDAYS)).')(\s+prochain)?\b/iu';

        if (preg_match($weekdayPattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            $date = $this->nextWeekday(self::WEEKDAYS[Str::lower($matches[1][0])]);

            if (! empty($matches[2][0])) {
                $date = $date->addWeek();
            }

            $raw = $matches[0][0];

            return [$date, $this->removeAt($text, $matches[0][1], strlen($raw)), $this->dateToken($date, $raw)];
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

            return [$date, $this->removeAt($text, $matches[0][1], strlen($raw)), $this->dateToken($date, $raw)];
        }

        return [null, $text, null];
    }

    /**
     * La puce affiche la date *résolue*, pas le mot tapé : c'est tout l'intérêt
     * de parser en direct — « vendredi » devient « ven. 17 oct. ».
     */
    private function dateToken(CarbonInterface $date, string $raw): QuickAddToken
    {
        return new QuickAddToken('date', $raw, $date->translatedFormat('D j M'));
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
