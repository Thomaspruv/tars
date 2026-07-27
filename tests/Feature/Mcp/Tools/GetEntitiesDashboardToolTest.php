<?php

use App\Enums\EntityRelationType;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetEntitiesDashboardTool;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Goal;
use App\Models\Task;

test('returns aggregate stats for every active entity', function () {
    $entity = Entity::factory()->create(['name' => 'Acme', 'type' => 'company', 'status' => 'active']);
    $other = Entity::factory()->create(['name' => 'Jean Dupont', 'status' => 'active']);

    Task::factory()->create(['entity_id' => $entity->id, 'status' => 'todo', 'due_date' => today()->addDays(3)]);
    Task::factory()->done()->create(['entity_id' => $entity->id]);
    Goal::factory()->create(['entity_id' => $entity->id]);
    EntityRelation::create(['entity_id' => $other->id, 'related_entity_id' => $entity->id, 'relation_type' => EntityRelationType::EmployedBy]);

    TarsServer::tool(GetEntitiesDashboardTool::class, [])
        ->assertOk()
        ->assertSee('Acme')
        ->assertSee('1 tâche(s) ouverte(s)')
        ->assertSee('1 objectif(s) lié(s)')
        ->assertSee('1 relation(s)');
});

test('excludes archived entities', function () {
    Entity::factory()->create(['name' => 'Archivée', 'status' => 'archived']);

    TarsServer::tool(GetEntitiesDashboardTool::class, [])->assertOk()->assertDontSee('Archivée');
});

test('reports no active entities gracefully', function () {
    TarsServer::tool(GetEntitiesDashboardTool::class, [])->assertOk()->assertSee('Aucune entité active');
});
