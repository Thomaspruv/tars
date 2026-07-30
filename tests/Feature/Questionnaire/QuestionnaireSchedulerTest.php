<?php

use App\Models\Questionnaire;
use App\Support\Questionnaire\QuestionnaireScheduler;

test('creates a run once its due date has arrived', function () {
    $questionnaire = Questionnaire::factory()->create([
        'frequency' => 'monthly',
        'anchor' => '1',
        'created_at' => today()->subMonths(2),
    ]);

    $created = (new QuestionnaireScheduler)->ensureRunsCreated();

    expect($created)->toBe(1);
    expect($questionnaire->runs()->count())->toBe(1);
    expect($questionnaire->runs()->first()->status->value)->toBe('pending');
});

test('does not create a duplicate run for an already-created due date', function () {
    $questionnaire = Questionnaire::factory()->create([
        'frequency' => 'monthly',
        'anchor' => '1',
        'created_at' => today()->subMonth(),
    ]);

    $scheduler = new QuestionnaireScheduler;
    $scheduler->ensureRunsCreated();
    $secondPass = $scheduler->ensureRunsCreated();

    expect($secondPass)->toBe(0);
    expect($questionnaire->runs()->count())->toBe(1);
});

test('a pending run does not block the creation of the next one', function () {
    $questionnaire = Questionnaire::factory()->create([
        'frequency' => 'monthly',
        'anchor' => '1',
        'created_at' => today()->subMonths(3),
    ]);

    $scheduler = new QuestionnaireScheduler;
    $scheduler->ensureRunsCreated();
    $scheduler->ensureRunsCreated();

    expect($questionnaire->runs()->count())->toBe(2);
    expect($questionnaire->runs()->where('status', 'pending')->count())->toBe(2);
});

test('an inactive questionnaire is ignored', function () {
    $questionnaire = Questionnaire::factory()->create([
        'frequency' => 'monthly',
        'anchor' => '1',
        'created_at' => today()->subMonths(2),
        'is_active' => false,
    ]);

    $created = (new QuestionnaireScheduler)->ensureRunsCreated();

    expect($created)->toBe(0);
    expect($questionnaire->runs()->count())->toBe(0);
});

test('does not create a run before its due date', function () {
    $questionnaire = Questionnaire::factory()->create([
        'frequency' => 'monthly',
        'anchor' => '1',
        'created_at' => today(),
    ]);

    $created = (new QuestionnaireScheduler)->ensureRunsCreated();

    expect($created)->toBe(0);
    expect($questionnaire->runs()->count())->toBe(0);
});
