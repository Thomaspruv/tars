<?php

use App\Models\Questionnaire;
use App\Support\Questionnaire\QuestionnaireScheduler;
use Illuminate\Support\Carbon;

// Freeze "now" to a date away from any monthly/yearly anchor boundary: tests
// below use bare today() and would otherwise become flaky whenever the suite
// happens to run on the 1st of a month or Dec 31. Also strip the "Bilan
// mensuel"/"Bilan annuel" questionnaires seeded by migration, which would
// otherwise become due on their own anchor day and inflate these counts.
beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 3, 15));
    Questionnaire::query()->delete();
});

afterEach(fn () => Carbon::setTestNow());

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
    Carbon::setTestNow(Carbon::create(2026, 7, 15));

    $questionnaire = Questionnaire::factory()->create([
        'frequency' => 'monthly',
        'anchor' => '1',
        'created_at' => today(),
    ]);

    $created = (new QuestionnaireScheduler)->ensureRunsCreated();

    expect($created)->toBe(0);
    expect($questionnaire->runs()->count())->toBe(0);
});

test('creates the first run in the same cycle when a freshly created questionnaire\'s anchor has not passed yet', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 5));

    $questionnaire = Questionnaire::factory()->create([
        'frequency' => 'monthly',
        'anchor' => '15',
        'created_at' => today(),
    ]);

    // Time passes to the anchor date. Before the fix, the first run would
    // have been miscomputed as 2026-08-15 (a full cycle late) and still
    // look "future" here, so no run would be created.
    Carbon::setTestNow(Carbon::create(2026, 7, 15));

    $created = (new QuestionnaireScheduler)->ensureRunsCreated();

    expect($created)->toBe(1);
    expect($questionnaire->runs()->first()->due_date->toDateString())->toBe('2026-07-15');
});

test('creates the first run for a brand-new yearly questionnaire the same year when the anchor has not passed', function () {
    Carbon::setTestNow(Carbon::create(2026, 7, 30));

    $questionnaire = Questionnaire::factory()->create([
        'frequency' => 'yearly',
        'anchor' => '12-31',
        'created_at' => today(),
    ]);

    // Time passes to the anchor date. Before the fix, the first run would
    // have been miscomputed as 2027-12-31 (a full year late).
    Carbon::setTestNow(Carbon::create(2026, 12, 31));

    $created = (new QuestionnaireScheduler)->ensureRunsCreated();

    expect($created)->toBe(1);
    expect($questionnaire->runs()->count())->toBe(1);
    expect($questionnaire->runs()->first()->due_date->toDateString())->toBe('2026-12-31');
});

test('a questionnaire with a malformed anchor does not prevent scheduling for other questionnaires', function () {
    $broken = Questionnaire::factory()->create([
        'frequency' => 'weekly',
        'anchor' => 'monday', // RecurrenceCalculator expects the 3-letter form 'mon'
        'created_at' => today()->subMonths(2),
    ]);
    $healthy = Questionnaire::factory()->create([
        'frequency' => 'monthly',
        'anchor' => '1',
        'created_at' => today()->subMonths(2),
    ]);

    $created = (new QuestionnaireScheduler)->ensureRunsCreated();

    expect($created)->toBe(1);
    expect($broken->runs()->count())->toBe(0);
    expect($healthy->runs()->count())->toBe(1);
});
