<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\DeleteListTool;
use App\Models\Checklist;
use App\Models\ChecklistItem;

test('deletes a list and its items', function () {
    $checklist = Checklist::factory()->create(['name' => 'Courses']);
    $item = ChecklistItem::factory()->create(['list_id' => $checklist->id]);

    TarsServer::tool(DeleteListTool::class, ['list' => 'courses'])->assertOk()->assertSee('supprimée');

    expect(Checklist::find($checklist->id))->toBeNull()
        ->and(ChecklistItem::find($item->id))->toBeNull();
});

test('fails without deleting anything when the list is not found', function () {
    TarsServer::tool(DeleteListTool::class, ['list' => 'introuvable'])->assertHasErrors(['Aucune liste']);
});
