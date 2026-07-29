<?php

use App\Enums\EntityContext;
use App\Enums\EntityType;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\CreateEntityTool;
use App\Models\Entity;

test('creates an entity', function () {
    TarsServer::tool(CreateEntityTool::class, [
        'name' => 'Appart Lilas',
        'type' => 'property',
    ])->assertOk()->assertSee('Appart Lilas');

    $entity = Entity::where('name', 'Appart Lilas')->firstOrFail();
    expect($entity->type)->toBe(EntityType::Property)
        ->and($entity->context)->toBe(EntityContext::Perso);
});

test('deduces a pro context for company entities', function () {
    TarsServer::tool(CreateEntityTool::class, ['name' => 'Acme', 'type' => 'company'])->assertOk();

    expect(Entity::where('name', 'Acme')->firstOrFail()->context)->toBe(EntityContext::Pro);
});

test('fails when the type is invalid', function () {
    TarsServer::tool(CreateEntityTool::class, ['name' => 'Acme', 'type' => 'not-a-type'])
        ->assertHasErrors();

    expect(Entity::where('name', 'Acme')->exists())->toBeFalse();
});
