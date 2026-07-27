<?php

use App\Enums\EntityStatus;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\UpdateEntityTool;
use App\Models\Entity;

test('updates the name, notes and status of an entity', function () {
    $entity = Entity::factory()->create(['name' => 'Old name', 'status' => 'active']);

    TarsServer::tool(UpdateEntityTool::class, [
        'entity' => 'old name',
        'name' => 'New name',
        'notes' => 'Contrat renouvelé.',
        'status' => 'archived',
    ])->assertOk()->assertSee('mise à jour');

    $entity->refresh();
    expect($entity->name)->toBe('New name')
        ->and($entity->notes)->toBe('Contrat renouvelé.')
        ->and($entity->status)->toBe(EntityStatus::Archived);
});

test('fails without changing anything when no field is provided', function () {
    $entity = Entity::factory()->create(['name' => 'Acme']);

    TarsServer::tool(UpdateEntityTool::class, ['entity' => 'acme'])->assertHasErrors(['Rien à mettre à jour']);

    expect($entity->fresh()->name)->toBe('Acme');
});

test('fails without changing anything when the entity is not found', function () {
    TarsServer::tool(UpdateEntityTool::class, ['entity' => 'introuvable', 'name' => 'New name'])
        ->assertHasErrors(['Aucune entité']);
});
