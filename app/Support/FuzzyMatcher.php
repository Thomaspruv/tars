<?php

namespace App\Support;

use Illuminate\Support\Collection;
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
        return $this->rankedMatches($candidates, $needle, $label, $threshold)->first();
    }

    /**
     * Rank every candidate scoring at or above the threshold, highest score first.
     *
     * @template TCandidate
     *
     * @param  iterable<TCandidate>  $candidates
     * @param  \Closure(TCandidate): string  $label
     * @return Collection<int, TCandidate>
     */
    public function rankedMatches(iterable $candidates, string $needle, \Closure $label, int $threshold = 55): Collection
    {
        $normalized = Str::of($needle)->lower()->replace('-', ' ')->toString();

        return collect($candidates)
            ->map(fn ($candidate): array => [$candidate, $this->score(Str::lower($label($candidate)), $normalized)])
            ->filter(fn (array $pair): bool => $pair[1] >= $threshold)
            ->sortByDesc(fn (array $pair): int|float => $pair[1])
            ->map(fn (array $pair): mixed => $pair[0])
            ->values();
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
