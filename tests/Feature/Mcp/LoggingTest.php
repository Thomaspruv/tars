<?php

use App\Enums\McpCallStatus;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\AddNoteTool;
use App\Mcp\Tools\AddTaskTool;
use App\Mcp\Tools\CaptureTool;
use App\Models\BrainDocument;
use App\Models\Entity;
use App\Models\McpCall;
use App\Models\Task;
use Illuminate\Support\Facades\File;

test('logs a successful call', function () {
    TarsServer::tool(CaptureTool::class, ['text' => 'test'])->assertOk();

    $call = McpCall::latest('id')->first();

    expect($call->tool)->toBe('capture')
        ->and($call->status)->toBe(McpCallStatus::Success)
        ->and($call->result_summary)->not->toBeNull()
        ->and($call->duration_ms)->toBeGreaterThanOrEqual(0);
});

test('logs an ambiguous call without any write happening', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);
    Entity::factory()->create(['name' => 'Appart Lilou']);

    TarsServer::tool(AddTaskTool::class, ['title' => 'Test', 'entity' => 'lil'])->assertOk();

    $call = McpCall::latest('id')->first();

    expect($call->tool)->toBe('add_task')
        ->and($call->status)->toBe(McpCallStatus::Ambiguous)
        ->and(Task::where('title', 'Test')->exists())->toBeFalse();
});

test('logs an unhandled exception as an error without breaking the response', function () {
    $vaultPath = sys_get_temp_dir().'/mcp-log-test-'.uniqid();
    File::ensureDirectoryExists($vaultPath);
    config(['brain.local_path' => $vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);

    // No git repository at $vaultPath and GitRepository is not mocked here, so the real
    // `git commit` invoked by AddNoteTool fails for real — a genuine unhandled exception.
    TarsServer::tool(AddNoteTool::class, ['content' => 'Test'])->assertHasErrors();

    $call = McpCall::latest('id')->first();

    expect($call->tool)->toBe('add_note')
        ->and($call->status)->toBe(McpCallStatus::Error)
        ->and($call->error_message)->not->toBeNull()
        ->and(BrainDocument::count())->toBe(0);

    File::deleteDirectory($vaultPath);
});
