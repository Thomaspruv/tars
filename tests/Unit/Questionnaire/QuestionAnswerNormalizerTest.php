<?php

use App\Support\Questionnaire\QuestionAnswerNormalizer;

test('normalizes a valid scale answer', function () {
    $result = (new QuestionAnswerNormalizer)->normalize(['text' => 'Satisfaction', 'type' => 'scale', 'scale_max' => 10], '8');

    expect($result)->toBe(['answerText' => null, 'answerNumeric' => 8.0]);
});

test('rejects a scale answer above scale_max', function () {
    $result = (new QuestionAnswerNormalizer)->normalize(['text' => 'Satisfaction', 'type' => 'scale', 'scale_max' => 10], '15');

    expect($result)->toBeNull();
});

test('rejects a scale answer below 1', function () {
    $result = (new QuestionAnswerNormalizer)->normalize(['text' => 'Satisfaction', 'type' => 'scale', 'scale_max' => 10], '0');

    expect($result)->toBeNull();
});

test('rejects a non-numeric scale answer', function () {
    $result = (new QuestionAnswerNormalizer)->normalize(['text' => 'Satisfaction', 'type' => 'scale', 'scale_max' => 10], 'beaucoup');

    expect($result)->toBeNull();
});

test('a scale answer respects a custom scale_max', function () {
    $result = (new QuestionAnswerNormalizer)->normalize(['text' => 'Note', 'type' => 'scale', 'scale_max' => 5], '5');

    expect($result)->toBe(['answerText' => null, 'answerNumeric' => 5.0]);
});

test('normalizes a valid number answer', function () {
    $result = (new QuestionAnswerNormalizer)->normalize(['text' => 'Combien de livres', 'type' => 'number'], '23');

    expect($result)->toBe(['answerText' => null, 'answerNumeric' => 23.0]);
});

test('rejects a number answer beyond the decimal(8,2) column range', function () {
    $result = (new QuestionAnswerNormalizer)->normalize(['text' => 'Montant', 'type' => 'number'], '1000000');

    expect($result)->toBeNull();
});

test('rejects a non-numeric number answer', function () {
    $result = (new QuestionAnswerNormalizer)->normalize(['text' => 'Montant', 'type' => 'number'], 'beaucoup');

    expect($result)->toBeNull();
});

test('normalizes boolean answers to oui/non', function () {
    $normalizer = new QuestionAnswerNormalizer;
    $question = ['text' => 'Content ?', 'type' => 'boolean'];

    expect($normalizer->normalize($question, 'oui'))->toBe(['answerText' => 'oui', 'answerNumeric' => null])
        ->and($normalizer->normalize($question, 'Yes'))->toBe(['answerText' => 'oui', 'answerNumeric' => null])
        ->and($normalizer->normalize($question, 'non'))->toBe(['answerText' => 'non', 'answerNumeric' => null])
        ->and($normalizer->normalize($question, 'no'))->toBe(['answerText' => 'non', 'answerNumeric' => null]);
});

test('rejects an unrecognized boolean answer', function () {
    $result = (new QuestionAnswerNormalizer)->normalize(['text' => 'Content ?', 'type' => 'boolean'], 'peut-être');

    expect($result)->toBeNull();
});

test('passes a text answer through unchanged', function () {
    $result = (new QuestionAnswerNormalizer)->normalize(['text' => 'Un mot', 'type' => 'text'], 'Bonne période');

    expect($result)->toBe(['answerText' => 'Bonne période', 'answerNumeric' => null]);
});
