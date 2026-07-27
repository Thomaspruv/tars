<?php

use App\Enums\EntityRelationType;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\UnlinkEntitiesTool;
use App\Models\Entity;
use App\Models\EntityRelation;

test('unlinks two entities regardless of the relation direction', function () {
    $person = Entity::factory()->create(['name' => 'Jean Dupont']);
    $company = Entity::factory()->create(['name' => 'Acme Corp']);

    EntityRelation::create([
        'entity_id' => $person->id,
        'related_entity_id' => $company->id,
        'relation_type' => EntityRelationType::EmployedBy,
    ]);

    TarsServer::tool(UnlinkEntitiesTool::class, ['entity' => 'acme', 'related_entity' => 'jean dupont'])
        ->assertOk()
        ->assertSee('supprimée');

    expect(EntityRelation::count())->toBe(0);
});

test('fails when there is no relation between the two entities', function () {
    Entity::factory()->create(['name' => 'Alpha']);
    Entity::factory()->create(['name' => 'Beta']);

    TarsServer::tool(UnlinkEntitiesTool::class, ['entity' => 'alpha', 'related_entity' => 'beta'])
        ->assertHasErrors(['Aucune relation']);
});
