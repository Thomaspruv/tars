<?php

namespace App\Support;

use Illuminate\Support\Str;

class FuzzyMatcher
{
    /**
     * Find the best-scoring candidate for a needle, or null if nothing clears the threshold.
     *
     * @template TCandidate
     *
     * @param  iterable<TCandidate>  $candidates
     * @param  \Closure(TCandidate): string  $label
     * @return TCandidate|null
     */
    public function bestMatch(iterable $candidates, string $needle, \Closure $label, int $threshold = 55): mixed
    {
        $normalized = Str::of($needle)->lower()->replace('-', ' ')->toString();

        $best = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $score = $this->score(Str::lower($label($candidate)), $normalized);

            if ($score >= $threshold && $score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function score(string $haystack, string $needle): int|float
    {
        similar_text($haystack, $needle, $percent);

        return match (true) {
            $haystack === $needle => 100,
            str_contains($haystack, $needle) => 90,
            default => $percent,
        };
    }
}
