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

test('it parses an hour, with or without minutes', function () {
    expect((new QuickAddParser)->parse('Appeler demain 14h')->time)->toBe('14:00');
    expect((new QuickAddParser)->parse('Appeler demain 14h30')->time)->toBe('14:30');
    expect((new QuickAddParser)->parse('Appeler demain à 9h05')->time)->toBe('09:05');

    // Une heure impossible n'est pas une heure : « 50h de ménage » reste du texte.
    expect((new QuickAddParser)->parse('Payer 50h de ménage')->time)->toBeNull();
});

test('it parses a recurrence in plain french', function () {
    expect((new QuickAddParser)->parse('TVA tous les mois p2')->recurrence)->toBe('monthly');
    expect((new QuickAddParser)->parse('Ménage chaque semaine')->recurrence)->toBe('weekly');
    expect((new QuickAddParser)->parse('Arroser tous les jours')->recurrence)->toBe('daily');
    expect((new QuickAddParser)->parse('Déclaration tous les ans')->recurrence)->toBe('yearly');

    // « chaque mardi » est une récurrence, pas une échéance : la date ne doit
    // pas manger le jour de la semaine.
    $weekly = (new QuickAddParser)->parse('Courir chaque mardi');
    expect($weekly->recurrence)->toBe('weekly:tue');
    expect($weekly->date)->toBeNull();
    expect($weekly->title)->toBe('Courir');
});

test('each token carries its kind, its raw text and its label', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);

    $result = (new QuickAddParser)->parse('Appeler le plombier demain 14h30 @Lilas p1');

    $tokens = collect($result->tokens)->keyBy(fn ($token): string => $token->kind);

    expect($tokens->keys()->all())->toBe(['date', 'time', 'entity', 'priority']);
    expect($tokens['time']->raw)->toBe('14h30');
    expect($tokens['time']->label)->toBe('14h30');
    expect($tokens['entity']->raw)->toBe('@Lilas');
    expect($tokens['entity']->label)->toBe('Appart Lilas');
    expect($tokens['priority']->label)->toBe('P1');
    expect($tokens['date']->label)->toBe(today()->addDay()->translatedFormat('D j M'));
});

test('an action verb with an hour stays a task', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);

    $result = (new QuickAddParser)->parse('Appeler le plombier demain 14h @Lilas p1');

    expect($result->kind())->toBe('task');
    expect($result->title)->toBe('Appeler le plombier');
    expect($result->time)->toBe('14:00');
    expect($result->priority)->toBe(TaskPriority::P1);
});

test('an hour without an action verb makes it an event, and a two-word name is read whole', function () {
    $entity = Entity::factory()->create(['name' => 'SARL Alpha']);

    $result = (new QuickAddParser)->parse('Réunion compta vendredi 10h @SARL Alpha');

    expect($result->kind())->toBe('event');
    expect($result->entity?->id)->toBe($entity->id);
    expect($result->title)->toBe('Réunion compta');
    expect($result->startsAt()->format('H:i'))->toBe('10:00');
});

test('a line that announces itself as a note is a note', function () {
    $goal = Goal::factory()->create(['title' => 'Lancer le site vitrine']);

    $result = (new QuickAddParser)->parse('Idée : newsletter #Lancer le site vitrine');

    expect($result->kind())->toBe('note');
    expect($result->goal?->id)->toBe($goal->id);

    expect((new QuickAddParser)->parse('Note : relancer plus tard')->kind())->toBe('note');
});

test('a capitalized date word is never swallowed by an entity reference', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);

    $result = (new QuickAddParser)->parse('Relancer @Lilas Jeudi');

    expect($result->entity?->name)->toBe('Appart Lilas');
    expect($result->date?->dayOfWeek)->toBe(Carbon::THURSDAY);
    expect($result->title)->toBe('Relancer');
});

test('the result knows how to turn a date and an hour into a start time', function () {
    $withBoth = (new QuickAddParser)->parse('Réunion demain 14h30');
    expect($withBoth->startsAt()->format('Y-m-d H:i'))
        ->toBe(today()->addDay()->format('Y-m-d').' 14:30');

    // Une heure sans date se pose sur aujourd'hui, une date sans heure à minuit.
    expect((new QuickAddParser)->parse('Réunion 9h')->startsAt()->format('Y-m-d H:i'))
        ->toBe(today()->format('Y-m-d').' 09:00');
    expect((new QuickAddParser)->parse('Réunion demain')->startsAt()->format('H:i'))->toBe('00:00');
    expect((new QuickAddParser)->parse('Réunion')->startsAt())->toBeNull();
});

test('a recurrence or an hour alone is enough to stop being an inbox item', function () {
    expect((new QuickAddParser)->parse('TVA tous les mois')->hasStructuredToken())->toBeTrue();
    expect((new QuickAddParser)->parse('Réunion 10h')->hasStructuredToken())->toBeTrue();
    expect((new QuickAddParser)->parse('Acheter du lait')->hasStructuredToken())->toBeFalse();
});
