<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\CreateListTool;
use App\Models\Checklist;
use App\Models\Entity;

test('creates a list with no entity', function () {
    TarsServer::tool(CreateListTool::class, ['name' => 'Courses'])->assertOk()->assertSee('Courses');

    $checklist = Checklist::where('name', 'Courses')->firstOrFail();
    expect($checklist->entity_id)->toBeNull()
        ->and($checklist->is_pinned)->toBeFalse();
});

test('creates a pinned list attached to an entity', function () {
    $entity = Entity::factory()->create(['name' => 'Appart Lilas']);

    TarsServer::tool(CreateListTool::class, ['name' => 'Travaux', 'entity' => 'lilas', 'pinned' => true])->assertOk();

    $checklist = Checklist::where('name', 'Travaux')->firstOrFail();
    expect($checklist->entity_id)->toBe($entity->id)
        ->and($checklist->is_pinned)->toBeTrue();
});

test('fails without creating anything when the entity is not found', function () {
    TarsServer::tool(CreateListTool::class, ['name' => 'Travaux', 'entity' => 'introuvable'])
        ->assertHasErrors(['Aucune entité']);

    expect(Checklist::where('name', 'Travaux')->exists())->toBeFalse();
});
