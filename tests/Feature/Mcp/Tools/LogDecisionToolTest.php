<?php

use App\Enums\DecisionSource;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\LogDecisionTool;
use App\Models\Decision;
use App\Models\Entity;

test('logs a decision from conversation', function () {
    TarsServer::tool(LogDecisionTool::class, ['content' => 'Objectif semi-marathon mis en pause', 'context' => 'Blessure au genou'])
        ->assertOk()
        ->assertSee('enregistrée');

    $decision = Decision::where('content', 'Objectif semi-marathon mis en pause')->firstOrFail();

    expect($decision->source)->toBe(DecisionSource::Conversation)
        ->and($decision->context)->toBe('Blessure au genou');
});

test('anchors the decision to an entity resolved by approximate name', function () {
    $entity = Entity::factory()->create(['name' => 'SARL Alpha']);

    TarsServer::tool(LogDecisionTool::class, ['content' => 'Reporter la TVA', 'entity' => 'sarl alpha'])->assertOk();

    expect(Decision::where('content', 'Reporter la TVA')->firstOrFail()->entity_id)->toBe($entity->id);
});

test('returns candidates without logging anything when the entity is ambiguous', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);
    Entity::factory()->create(['name' => 'Appart Lilou']);

    TarsServer::tool(LogDecisionTool::class, ['content' => 'Test', 'entity' => 'lil'])
        ->assertOk()
        ->assertSee('Précise laquelle');

    expect(Decision::where('content', 'Test')->exists())->toBeFalse();
});

test('fails without logging anything when the entity is not found', function () {
    TarsServer::tool(LogDecisionTool::class, ['content' => 'Test', 'entity' => 'introuvable'])
        ->assertHasErrors(['Aucune entité']);

    expect(Decision::where('content', 'Test')->exists())->toBeFalse();
});
