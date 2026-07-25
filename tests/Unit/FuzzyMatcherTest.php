<?php

use App\Support\FuzzyMatcher;

test('returns an exact match', function () {
    $best = (new FuzzyMatcher)->bestMatch(['SARL Alpha', 'SAS Beta'], 'sarl alpha', fn (string $c): string => strtolower($c));

    expect($best)->toBe('SARL Alpha');
});

test('returns a containing match over a weaker one', function () {
    $best = (new FuzzyMatcher)->bestMatch(['Appart Lilas', 'Appart Lyon'], 'lilas', fn (string $c): string => strtolower($c));

    expect($best)->toBe('Appart Lilas');
});

test('returns null when nothing clears the threshold', function () {
    $best = (new FuzzyMatcher)->bestMatch(['SARL Alpha', 'SAS Beta'], 'zzz', fn (string $c): string => strtolower($c));

    expect($best)->toBeNull();
});

test('picks the highest-scoring candidate', function () {
    $best = (new FuzzyMatcher)->bestMatch(['Lilas', 'Lil'], 'lilas', fn (string $c): string => strtolower($c));

    expect($best)->toBe('Lilas');
});

test('score is symmetric for exact matches and additive for containment', function () {
    $matcher = new FuzzyMatcher;

    expect($matcher->score('lilas', 'lilas'))->toBe(100)
        ->and($matcher->score('appart lilas', 'lilas'))->toBe(90);
});
