<?php

use App\Models\Entity;
use App\Models\Goal;
use App\Support\Brain\AnchorResolver;
use App\Support\Brain\ParsedDocument;

test('resolves an entity by fuzzy name', function () {
    $entity = Entity::factory()->create(['name' => 'SARL Alpha']);

    $resolved = (new AnchorResolver)->resolveEntity('sarl alpha');

    expect($resolved->is($entity))->toBeTrue();
});

test('resolves a goal by fuzzy title', function () {
    $goal = Goal::factory()->create(['title' => 'Rénover la salle de bain']);

    $resolved = (new AnchorResolver)->resolveGoal('renover salle de bain');

    expect($resolved->is($goal))->toBeTrue();
});

test('returns null when nothing matches', function () {
    Entity::factory()->create(['name' => 'SARL Alpha']);

    expect((new AnchorResolver)->resolveEntity('totally unrelated'))->toBeNull();
});

test('resolves anchors from frontmatter and wikilinks', function () {
    $entity = Entity::factory()->create(['name' => 'SARL Alpha']);
    $goal = Goal::factory()->create(['title' => 'Semi-marathon 2027']);
    $otherEntity = Entity::factory()->create(['name' => 'Appart Lilas']);

    $document = new ParsedDocument(
        title: 'Réunion',
        frontmatter: ['entity' => 'SARL Alpha'],
        content: 'Vu [[Semi-marathon 2027]] et passage par [[Appart Lilas]].',
        wikilinks: ['Semi-marathon 2027', 'Appart Lilas'],
        hash: 'abc',
    );

    $resolved = (new AnchorResolver)->resolve($document);

    expect(collect($resolved['entities'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$entity->id, $otherEntity->id])->sort()->values()->all())
        ->and(collect($resolved['goals'])->pluck('id')->all())
        ->toBe([$goal->id]);
});

test('ignores frontmatter anchors that do not resolve to anything', function () {
    $document = new ParsedDocument(
        title: 'Note',
        frontmatter: ['entity' => 'Inconnu'],
        content: 'Rien.',
        wikilinks: [],
        hash: 'abc',
    );

    $resolved = (new AnchorResolver)->resolve($document);

    expect($resolved['entities'])->toBe([])
        ->and($resolved['goals'])->toBe([]);
});
