<?php

use App\Enums\TaskPriority;
use App\Models\Entity;
use App\Models\Goal;
use App\Support\QuickAdd\QuickAddParser;
use Illuminate\Support\Carbon;

test('it parses a priority token', function () {
    $result = (new QuickAddParser)->parse('Preparer le bilan p1');

    expect($result->priority)->toBe(TaskPriority::P1);
    expect($result->title)->toBe('Preparer le bilan');
});

test('it does not match a priority-like substring inside a word', function () {
    $result = (new QuickAddParser)->parse('Racheter du sirop1');

    expect($result->priority)->toBeNull();
});

test('it fuzzy matches an entity by exact and partial name', function () {
    $entity = Entity::factory()->create(['name' => 'Appart Lilas']);

    $exact = (new QuickAddParser)->parse('Relancer le plombier @lilas');
    expect($exact->entity?->id)->toBe($entity->id);
    expect($exact->title)->toBe('Relancer le plombier');
});

test('it fuzzy matches a goal by its tag or title', function () {
    $goal = Goal::factory()->create(['title' => '500k CA SARL Alpha']);

    $byTag = (new QuickAddParser)->parse('Preparer bilan #500k-ca-sarl-alpha p1');
    expect($byTag->goal?->id)->toBe($goal->id);

    $byPartialTitle = (new QuickAddParser)->parse('Preparer bilan #sarl');
    expect($byPartialTitle->goal?->id)->toBe($goal->id);
});

test('it returns no goal when nothing matches closely enough', function () {
    Goal::factory()->create(['title' => '500k CA SARL Alpha']);

    $result = (new QuickAddParser)->parse('Une tache #xyzxyz');

    expect($result->goal)->toBeNull();
});

test('it parses relative french dates', function () {
    expect((new QuickAddParser)->parse('Appeler demain')->date->toDateString())
        ->toBe(today()->addDay()->toDateString());

    expect((new QuickAddParser)->parse('Appeler après-demain')->date->toDateString())
        ->toBe(today()->addDays(2)->toDateString());

    expect((new QuickAddParser)->parse("Appeler aujourd'hui")->date->toDateString())
        ->toBe(today()->toDateString());
});

test('it parses a weekday name as the next occurrence of that day', function () {
    $result = (new QuickAddParser)->parse('Relancer le plombier vendredi');

    $expected = today();
    while ($expected->dayOfWeek !== Carbon::FRIDAY) {
        $expected = $expected->addDay();
    }

    expect($result->date->toDateString())->toBe($expected->toDateString());
});

test('it adds a week when "prochain" is specified', function () {
    $withoutProchain = (new QuickAddParser)->parse('Relancer vendredi')->date;
    $withProchain = (new QuickAddParser)->parse('Relancer vendredi prochain')->date;

    expect($withProchain->toDateString())->toBe($withoutProchain->copy()->addWeek()->toDateString());
});

test('it parses a numeric date', function () {
    $result = (new QuickAddParser)->parse('Anniversaire le 25/12');

    expect($result->date->format('d/m'))->toBe('25/12');
    expect($result->title)->toBe('Anniversaire le');
});

test('it combines multiple tokens and strips them from the title', function () {
    $entity = Entity::factory()->create(['name' => 'Appart Lilas']);
    $goal = Goal::factory()->create(['title' => '500k CA SARL Alpha']);

    $result = (new QuickAddParser)->parse('préparer bilan demain #500k-ca-sarl-alpha p1 @lilas');

    expect($result->title)->toBe('préparer bilan');
    expect($result->date->toDateString())->toBe(today()->addDay()->toDateString());
    expect($result->goal?->id)->toBe($goal->id);
    expect($result->priority)->toBe(TaskPriority::P1);
    expect($result->entity?->id)->toBe($entity->id);
    expect($result->hasStructuredToken())->toBeTrue();
});

test('plain text with no recognizable syntax has no structured token', function () {
    $result = (new QuickAddParser)->parse('Acheter du lait');

    expect($result->hasStructuredToken())->toBeFalse();
    expect($result->title)->toBe('Acheter du lait');
    expect($result->tokens)->toBeEmpty();
});
