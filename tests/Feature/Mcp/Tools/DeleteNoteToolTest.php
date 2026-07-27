<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\DeleteNoteTool;
use App\Models\BrainDocument;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/mcp-vault-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath.'/TARS');
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('deletes the note file, commits the deletion, and removes the index row', function () {
    $relativePath = 'TARS/2026-07-27-carreleur.md';
    File::put($this->vaultPath.'/'.$relativePath, "# Carreleur\n\nLe carreleur passe mardi.");
    $document = BrainDocument::factory()->create(['path' => $relativePath, 'title' => 'Carreleur']);

    $this->mock(GitRepository::class, function ($mock) use ($relativePath): void {
        $mock->shouldReceive('commit')->once()->with(Mockery::type('string'), $relativePath, Mockery::type('string'));
    });

    TarsServer::tool(DeleteNoteTool::class, ['note' => 'carreleur'])
        ->assertOk()
        ->assertSee('supprimée');

    expect(File::exists($this->vaultPath.'/'.$relativePath))->toBeFalse()
        ->and(BrainDocument::find($document->id))->toBeNull();
});

test('fails cleanly when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    TarsServer::tool(DeleteNoteTool::class, ['note' => 'carreleur'])->assertHasErrors(['vault']);
});

test('fails without deleting anything when the note is not found', function () {
    TarsServer::tool(DeleteNoteTool::class, ['note' => 'introuvable'])->assertHasErrors(['Aucune note']);
});
