<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\AddEventTool;
use App\Models\Entity;
use App\Models\Event;

test('creates an event with a french date and a time', function () {
    TarsServer::tool(AddEventTool::class, ['title' => 'RDV dentiste', 'date' => 'demain', 'time' => '14:30'])
        ->assertOk()
        ->assertSee('RDV dentiste');

    $event = Event::where('title', 'RDV dentiste')->firstOrFail();

    expect($event->starts_at->toDateString())->toBe(today()->addDay()->toDateString())
        ->and($event->starts_at->format('H:i'))->toBe('14:30');
});

test('defaults to midnight without a time', function () {
    TarsServer::tool(AddEventTool::class, ['title' => 'Anniversaire', 'date' => 'demain'])->assertOk();

    $event = Event::where('title', 'Anniversaire')->firstOrFail();

    expect($event->starts_at->format('H:i'))->toBe('00:00');
});

test('resolves the entity by approximate name', function () {
    $entity = Entity::factory()->create(['name' => 'Appart Lilas']);

    TarsServer::tool(AddEventTool::class, ['title' => 'État des lieux', 'date' => 'demain', 'entity' => 'lilas'])->assertOk();

    expect(Event::where('title', 'État des lieux')->firstOrFail()->entity_id)->toBe($entity->id);
});

test('fails without creating anything when the date is not understood', function () {
    TarsServer::tool(AddEventTool::class, ['title' => 'Test', 'date' => 'zorglub'])
        ->assertHasErrors(["n'ai pas compris"]);

    expect(Event::where('title', 'Test')->exists())->toBeFalse();
});

test('returns candidates without creating anything when the entity is ambiguous', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);
    Entity::factory()->create(['name' => 'Appart Lilou']);

    TarsServer::tool(AddEventTool::class, ['title' => 'Test', 'date' => 'demain', 'entity' => 'lil'])
        ->assertOk()
        ->assertSee('Précise laquelle');

    expect(Event::where('title', 'Test')->exists())->toBeFalse();
});
