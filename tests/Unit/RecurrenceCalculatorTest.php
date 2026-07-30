<?php

use App\Support\Recurrence\RecurrenceCalculator;
use Carbon\Carbon;

test('daily adds one day', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('daily', Carbon::create(2026, 7, 15));

    expect($result->toDateString())->toBe('2026-07-16');
});

test('weekly without a day adds one week', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('weekly', Carbon::create(2026, 7, 15));

    expect($result->toDateString())->toBe('2026-07-22');
});

test('weekly with a day finds the next occurrence of that weekday', function () {
    // 2026-07-15 is a Wednesday.
    $result = (new RecurrenceCalculator)->nextOccurrence('weekly:mon', Carbon::create(2026, 7, 15));

    expect($result->toDateString())->toBe('2026-07-20');
    expect($result->isMonday())->toBeTrue();
});

test('weekly on the same weekday still advances to next week, not the same day', function () {
    // 2026-07-15 is itself a Wednesday.
    $result = (new RecurrenceCalculator)->nextOccurrence('weekly:wed', Carbon::create(2026, 7, 15));

    expect($result->toDateString())->toBe('2026-07-22');
});

test('monthly without a day adds one month without overflow', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('monthly', Carbon::create(2026, 1, 31));

    expect($result->toDateString())->toBe('2026-02-28');
});

test('monthly with a day targets that day in the following month', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('monthly:15', Carbon::create(2026, 7, 1));

    expect($result->toDateString())->toBe('2026-08-15');
});

test('monthly with a day clamps to the last day of a shorter month', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('monthly:31', Carbon::create(2026, 1, 1));

    expect($result->toDateString())->toBe('2026-02-28');
});

test('quarterly without a day adds three months without overflow', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('quarterly', Carbon::create(2026, 1, 31));

    expect($result->toDateString())->toBe('2026-04-30');
});

test('quarterly with a day targets that day three months later', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('quarterly:1', Carbon::create(2026, 7, 1));

    expect($result->toDateString())->toBe('2026-10-01');
});

test('quarterly with a day clamps to the last day of a shorter month', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('quarterly:31', Carbon::create(2026, 1, 1));

    expect($result->toDateString())->toBe('2026-04-30');
});

test('yearly without a date adds one year without overflow', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('yearly', Carbon::create(2028, 2, 29));

    expect($result->toDateString())->toBe('2029-02-28');
});

test('yearly with a month-day targets that date next year', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('yearly:03-15', Carbon::create(2026, 7, 1));

    expect($result->toDateString())->toBe('2027-03-15');
});

test('yearly with a month-day clamps to the last day of a shorter month', function () {
    $result = (new RecurrenceCalculator)->nextOccurrence('yearly:02-29', Carbon::create(2026, 1, 1));

    expect($result->toDateString())->toBe('2027-02-28');
});

test('it throws for an unknown pattern', function () {
    (new RecurrenceCalculator)->nextOccurrence('hourly', Carbon::create(2026, 7, 15));
})->throws(InvalidArgumentException::class);

test('it throws for an unknown weekday', function () {
    (new RecurrenceCalculator)->nextOccurrence('weekly:xyz', Carbon::create(2026, 7, 15));
})->throws(InvalidArgumentException::class);

test('firstOccurrenceOnOrAfter returns the anchor date this cycle when it has not passed yet', function () {
    // Reproduces the confirmed bug: a questionnaire created 2026-07-30 with a
    // yearly:12-31 anchor must get its first run this same year, not next.
    $result = (new RecurrenceCalculator)->firstOccurrenceOnOrAfter('yearly:12-31', Carbon::create(2026, 7, 30));

    expect($result->toDateString())->toBe('2026-12-31');
});

test('firstOccurrenceOnOrAfter advances to the next cycle when the anchor already passed', function () {
    $result = (new RecurrenceCalculator)->firstOccurrenceOnOrAfter('yearly:03-15', Carbon::create(2026, 7, 1));

    expect($result->toDateString())->toBe('2027-03-15');
});

test('firstOccurrenceOnOrAfter for monthly stays in the same month when the day has not passed', function () {
    $result = (new RecurrenceCalculator)->firstOccurrenceOnOrAfter('monthly:15', Carbon::create(2026, 7, 5));

    expect($result->toDateString())->toBe('2026-07-15');
});

test('firstOccurrenceOnOrAfter for monthly advances to next month when the day already passed', function () {
    $result = (new RecurrenceCalculator)->firstOccurrenceOnOrAfter('monthly:15', Carbon::create(2026, 7, 20));

    expect($result->toDateString())->toBe('2026-08-15');
});

test('firstOccurrenceOnOrAfter for quarterly stays in the same quarter-month when the day has not passed', function () {
    $result = (new RecurrenceCalculator)->firstOccurrenceOnOrAfter('quarterly:1', Carbon::create(2026, 7, 1));

    expect($result->toDateString())->toBe('2026-07-01');
});

test('firstOccurrenceOnOrAfter for quarterly advances to the next quarter when the day already passed', function () {
    $result = (new RecurrenceCalculator)->firstOccurrenceOnOrAfter('quarterly:1', Carbon::create(2026, 7, 5));

    expect($result->toDateString())->toBe('2026-10-01');
});

test('firstOccurrenceOnOrAfter for weekly finds the upcoming weekday, including today', function () {
    // 2026-07-20 is itself a Monday.
    $result = (new RecurrenceCalculator)->firstOccurrenceOnOrAfter('weekly:mon', Carbon::create(2026, 7, 20));

    expect($result->toDateString())->toBe('2026-07-20');
});

test('firstOccurrenceOnOrAfter for weekly advances past an already-passed weekday this week', function () {
    // 2026-07-15 is a Wednesday; the Monday of that week (07-13) already passed.
    $result = (new RecurrenceCalculator)->firstOccurrenceOnOrAfter('weekly:mon', Carbon::create(2026, 7, 15));

    expect($result->toDateString())->toBe('2026-07-20');
});

test('firstOccurrenceOnOrAfter for daily returns the same day', function () {
    $result = (new RecurrenceCalculator)->firstOccurrenceOnOrAfter('daily', Carbon::create(2026, 7, 15));

    expect($result->toDateString())->toBe('2026-07-15');
});
