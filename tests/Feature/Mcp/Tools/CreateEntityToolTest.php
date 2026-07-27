<?php

use App\Enums\EntityContext;
use App\Enums\EntityType;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\CreateEntityTool;
use App\Models\Entity;
use App\Models\LifeArea;

test('creates an entity resolving the life area by name', function () {
    $lifeArea = LifeArea::factory()->create(['name' => 'Immobilier']);

    TarsServer::tool(CreateEntityTool::class, [
        'name' => 'Appart Lilas',
        'type' => 'property',
        'life_area' => 'immo',
    ])->assertOk()->assertSee('Appart Lilas');

    $entity = Entity::where('name', 'Appart Lilas')->firstOrFail();
    expect($entity->type)->toBe(EntityType::Property)
        ->and($entity->life_area_id)->toBe($lifeArea->id)
        ->and($entity->context)->toBe(EntityContext::Perso);
});

test('deduces a pro context for company entities', function () {
    LifeArea::factory()->create(['name' => 'Pro']);

    TarsServer::tool(CreateEntityTool::class, ['name' => 'Acme', 'type' => 'company', 'life_area' => 'pro'])->assertOk();

    expect(Entity::where('name', 'Acme')->firstOrFail()->context)->toBe(EntityContext::Pro);
});

test('fails without creating anything when the life area is not found', function () {
    TarsServer::tool(CreateEntityTool::class, ['name' => 'Acme', 'type' => 'company', 'life_area' => 'introuvable'])
        ->assertHasErrors(['Aucun domaine de vie']);

    expect(Entity::where('name', 'Acme')->exists())->toBeFalse();
});

test('fails when the type is invalid', function () {
    LifeArea::factory()->create(['name' => 'Pro']);

    TarsServer::tool(CreateEntityTool::class, ['name' => 'Acme', 'type' => 'not-a-type', 'life_area' => 'pro'])
        ->assertHasErrors();

    expect(Entity::where('name', 'Acme')->exists())->toBeFalse();
});
