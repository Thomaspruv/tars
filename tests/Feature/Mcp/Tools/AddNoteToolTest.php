<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\AddNoteTool;
use App\Models\BrainDocument;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/mcp-vault-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('writes a note file in TARS/, commits it, and indexes it', function () {
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('commit')->once();
    });

    TarsServer::tool(AddNoteTool::class, ['content' => 'Le carreleur passe mardi'])
        ->assertOk()
        ->assertSee('Note ajoutée');

    $files = File::allFiles($this->vaultPath.'/TARS');
    expect($files)->toHaveCount(1);

    $document = BrainDocument::where('content', 'like', '%carreleur%')->first();
    expect($document)->not->toBeNull()
        ->and($document->frontmatter['source'])->toBe('hermes');
});

test('fails cleanly when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    TarsServer::tool(AddNoteTool::class, ['content' => 'Test'])
        ->assertHasErrors(['vault']);

    expect(BrainDocument::count())->toBe(0);
});
