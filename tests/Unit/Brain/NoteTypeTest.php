<?php

use App\Support\Brain\Schema\NoteType;

test('every type has a label and a target folder', function (NoteType $type) {
    expect($type->label())->not->toBeEmpty()
        ->and($type->folder())->not->toBeEmpty();
})->with(NoteType::cases());

test('the dated types are the stacked ones', function () {
    expect(array_map(
        fn (NoteType $type): string => $type->value,
        array_values(array_filter(NoteType::cases(), fn (NoteType $type): bool => $type->isDated()))
    ))->toBe(['meeting', 'decision', 'journal', 'veille']);
});

test('only project, veille and a-traiter carry a status', function () {
    expect(array_map(
        fn (NoteType $type): string => $type->value,
        array_values(array_filter(NoteType::cases(), fn (NoteType $type): bool => $type->requiresStatus()))
    ))->toBe(['project', 'veille', 'a-traiter']);
});

test('the folders follow the target tree', function (string $type, string $folder) {
    expect(NoteType::from($type)->folder())->toBe($folder);
})->with([
    ['project', 'Projets'],
    ['person', 'Personnes'],
    ['org', 'Organisations'],
    ['meeting', 'Réunions'],
    ['note', 'Notes'],
    ['decision', 'Décisions'],
    ['journal', 'Journal'],
    ['veille', 'Veille'],
    ['skill', 'Skills'],
    ['profil', 'Profil'],
    ['a-traiter', 'TARS'],
    ['archive', 'Archives'],
]);
