<?php

use App\Enums\EntityRelationType;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\LinkEntitiesTool;
use App\Models\Entity;
use App\Models\EntityRelation;

test('links two entities with a typed relation', function () {
    $person = Entity::factory()->create(['name' => 'Jean Dupont']);
    $company = Entity::factory()->create(['name' => 'Acme Corp']);

    TarsServer::tool(LinkEntitiesTool::class, [
        'entity' => 'jean dupont',
        'related_entity' => 'acme',
        'relation_type' => 'employed_by',
    ])->assertOk()->assertSee('employé de');

    $relation = EntityRelation::firstOrFail();
    expect($relation->entity_id)->toBe($person->id)
        ->and($relation->related_entity_id)->toBe($company->id)
        ->and($relation->relation_type)->toBe(EntityRelationType::EmployedBy);
});

test('rejects linking an entity to itself', function () {
    Entity::factory()->create(['name' => 'Solo']);

    TarsServer::tool(LinkEntitiesTool::class, [
        'entity' => 'solo',
        'related_entity' => 'solo',
        'relation_type' => 'other',
    ])->assertHasErrors(['elle-même']);

    expect(EntityRelation::count())->toBe(0);
});

test('does not create a duplicate relation', function () {
    $a = Entity::factory()->create(['name' => 'Alpha']);
    $b = Entity::factory()->create(['name' => 'Beta']);

    TarsServer::tool(LinkEntitiesTool::class, ['entity' => 'alpha', 'related_entity' => 'beta', 'relation_type' => 'other'])->assertOk();
    TarsServer::tool(LinkEntitiesTool::class, ['entity' => 'alpha', 'related_entity' => 'beta', 'relation_type' => 'other'])->assertOk();

    expect(EntityRelation::count())->toBe(1);
});

test('fails without creating anything when an entity is not found', function () {
    Entity::factory()->create(['name' => 'Alpha']);

    TarsServer::tool(LinkEntitiesTool::class, ['entity' => 'alpha', 'related_entity' => 'introuvable', 'relation_type' => 'other'])
        ->assertHasErrors(['Aucune entité']);

    expect(EntityRelation::count())->toBe(0);
});
