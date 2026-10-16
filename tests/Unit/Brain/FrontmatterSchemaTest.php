<?php

use App\Support\Brain\Schema\FrontmatterSchema;
use App\Support\Brain\Schema\NoteType;

function conformant(array $overrides = []): array
{
    return [...[
        'schema' => 3,
        'type' => 'meeting',
        'title' => 'Point HubSpot avec Marc',
        'date' => '2026-10-16',
        'source' => 'hermes',
    ], ...$overrides];
}

test('accepts a complete v3 frontmatter', function () {
    $result = (new FrontmatterSchema)->validate(conformant());

    expect($result->isConformant)->toBeTrue()
        ->and($result->errors)->toBe([])
        ->and($result->schemaVersion)->toBe(3)
        ->and($result->noteType)->toBe(NoteType::Meeting);
});

test('accepts every documented status and source form', function (string $key, string $value) {
    expect((new FrontmatterSchema)->validate(conformant(['type' => 'project', 'status' => 'active', $key => $value]))->isConformant)->toBeTrue();
})->with([
    ['status', 'active'],
    ['status', 'paused'],
    ['status', 'done'],
    ['status', 'archived'],
    ['source', 'thomas'],
    ['source', 'hermes'],
    ['source', 'agent-curateur'],
    ['source', 'connector-gmail'],
]);

test('tolerates a v2 frontmatter instead of throwing', function () {
    $result = (new FrontmatterSchema)->validate(['type' => 'insight', 'date' => '2026-07-26', 'entity' => 'SARL Alpha']);

    expect($result->isConformant)->toBeFalse()
        ->and($result->schemaVersion)->toBeNull()
        ->and($result->noteType)->toBeNull()
        ->and($result->errors)->toHaveCount(3);
});

test('reports a missing schema version', function () {
    $result = (new FrontmatterSchema)->validate(conformant(['schema' => null]));

    expect($result->isConformant)->toBeFalse()
        ->and($result->errorSentence())->toContain('`schema` manquant');
});

test('reports an outdated schema version', function () {
    $result = (new FrontmatterSchema)->validate(conformant(['schema' => 2]));

    expect($result->isConformant)->toBeFalse()
        ->and($result->schemaVersion)->toBe(2)
        ->and($result->errorSentence())->toContain('obsolète');
});

test('reports an unknown type', function () {
    $result = (new FrontmatterSchema)->validate(conformant(['type' => 'insight']));

    expect($result->isConformant)->toBeFalse()
        ->and($result->errorSentence())->toContain('Type `insight` inconnu');
});

test('reports a missing title and a missing date', function () {
    $result = (new FrontmatterSchema)->validate(['schema' => 3, 'type' => 'note']);

    expect($result->isConformant)->toBeFalse()
        ->and($result->errorSentence())->toContain('`title` manquant')
        ->and($result->errorSentence())->toContain('`date` manquant');
});

test('requires a status for the types that carry one', function (string $type) {
    $result = (new FrontmatterSchema)->validate(conformant(['type' => $type]));

    expect($result->isConformant)->toBeFalse()
        ->and($result->errorSentence())->toContain('`status` requis');
})->with(['project', 'veille', 'a-traiter']);

test('does not require a status for the other types', function () {
    expect((new FrontmatterSchema)->validate(conformant(['type' => 'person']))->isConformant)->toBeTrue();
});

test('rejects an unknown status', function () {
    $result = (new FrontmatterSchema)->validate(conformant(['type' => 'project', 'status' => 'en-cours']));

    expect($result->isConformant)->toBeFalse()
        ->and($result->errorSentence())->toContain('Statut `en-cours` inconnu');
});

test('rejects an unreadable date', function () {
    $result = (new FrontmatterSchema)->validate(conformant(['date' => '16/10/2026']));

    expect($result->isConformant)->toBeFalse()
        ->and($result->errorSentence())->toContain('date illisible');
});

test('rejects an invalid source', function () {
    $result = (new FrontmatterSchema)->validate(conformant(['source' => 'gmail']));

    expect($result->isConformant)->toBeFalse()
        ->and($result->errorSentence())->toContain('Source `gmail` invalide');
});

test('normalises a single entity and goal into a list', function () {
    $result = (new FrontmatterSchema)->validate(conformant(['entity' => 'Marc', 'goal' => '500k CA']));

    expect($result->isConformant)->toBeTrue()
        ->and($result->normalized['entity'])->toBe(['Marc'])
        ->and($result->normalized['goal'])->toBe(['500k CA']);
});

test('keeps an entity list as a list', function () {
    $result = (new FrontmatterSchema)->validate(conformant(['entity' => ['Marc', 'SARL Alpha']]));

    expect($result->normalized['entity'])->toBe(['Marc', 'SARL Alpha']);
});

test('normalises dates to ISO strings', function () {
    $result = (new FrontmatterSchema)->validate(conformant(['date' => new DateTimeImmutable('2026-10-16 14:00'), 'updated' => '2026-10-17']));

    expect($result->isConformant)->toBeTrue()
        ->and($result->normalized['date'])->toBe('2026-10-16')
        ->and($result->normalized['updated'])->toBe('2026-10-17');
});

test('knows the exempt zones and the generated files', function () {
    expect(FrontmatterSchema::isExempt('Profil/perso.md'))->toBeTrue()
        ->and(FrontmatterSchema::isExempt('.hermes/active-context.md'))->toBeTrue()
        ->and(FrontmatterSchema::isExempt('Notes/fiscalite.md'))->toBeFalse()
        ->and(FrontmatterSchema::isGenerated('README.md'))->toBeTrue()
        ->and(FrontmatterSchema::isGenerated('Personnes/_index.md'))->toBeTrue()
        ->and(FrontmatterSchema::isGenerated('Personnes/marc.md'))->toBeFalse();
});

test('accepts an unquoted obsidian date read back as a timestamp', function () {
    $result = (new FrontmatterSchema)->validate(conformant(['date' => 1792108800]));

    expect($result->isConformant)->toBeTrue()
        ->and($result->normalized['date'])->toBe('2026-10-16');
});
