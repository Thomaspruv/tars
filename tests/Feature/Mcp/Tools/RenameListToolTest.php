<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\RenameListTool;
use App\Models\Checklist;

test('renames an existing list', function () {
    $checklist = Checklist::factory()->create(['name' => 'Courses']);

    TarsServer::tool(RenameListTool::class, ['list' => 'courses', 'name' => 'Courses de la semaine'])
        ->assertOk()
        ->assertSee('Courses de la semaine');

    expect($checklist->fresh()->name)->toBe('Courses de la semaine');
});

test('fails without renaming anything when the list is not found', function () {
    TarsServer::tool(RenameListTool::class, ['list' => 'introuvable', 'name' => 'Nouveau nom'])
        ->assertHasErrors(['Aucune liste']);
});
