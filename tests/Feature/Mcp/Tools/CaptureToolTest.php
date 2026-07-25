<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\CaptureTool;
use App\Models\InboxItem;

test('captures raw text into the inbox', function () {
    TarsServer::tool(CaptureTool::class, ['text' => 'comparer les assurances'])
        ->assertOk()
        ->assertSee('inbox');

    expect(InboxItem::where('content', 'comparer les assurances')->exists())->toBeTrue();
});
