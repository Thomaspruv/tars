<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\AddToListTool;
use App\Mcp\Tools\GetListTool;
use App\Models\Checklist;

test('adds an item to a list by approximate name', function () {
    $list = Checklist::factory()->create(['name' => 'Courses']);

    TarsServer::tool(AddToListTool::class, ['list' => 'courses', 'item' => 'Lait'])
        ->assertOk()
        ->assertSee('Lait')
        ->assertSee('Courses');

    expect($list->items()->where('content', 'Lait')->exists())->toBeTrue();
});

test('proposes existing lists when the list is not found', function () {
    Checklist::factory()->create(['name' => 'Courses']);
    Checklist::factory()->create(['name' => 'Travaux SDB']);

    TarsServer::tool(AddToListTool::class, ['list' => 'introuvable', 'item' => 'Lait'])
        ->assertHasErrors(['Courses', 'Travaux SDB']);
});

test('returns candidates without adding anything when the list is ambiguous', function () {
    Checklist::factory()->create(['name' => 'Courses']);
    Checklist::factory()->create(['name' => 'Courses Lyon']);

    TarsServer::tool(AddToListTool::class, ['list' => 'courses', 'item' => 'Lait'])
        ->assertOk()
        ->assertSee('Précise laquelle');
});

test('reads a list with checked and unchecked items', function () {
    $list = Checklist::factory()->create(['name' => 'Courses']);
    $list->items()->create(['content' => 'Lait', 'position' => 0]);
    $list->items()->create(['content' => 'Pain', 'checked_at' => now(), 'position' => 1]);

    TarsServer::tool(GetListTool::class, ['list' => 'courses'])
        ->assertOk()
        ->assertSee('Lait')
        ->assertSee('Pain (fait)');
});

test('says when a list is empty', function () {
    Checklist::factory()->create(['name' => 'Courses']);

    TarsServer::tool(GetListTool::class, ['list' => 'courses'])
        ->assertOk()
        ->assertSee('est vide');
});
