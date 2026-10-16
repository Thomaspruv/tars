<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\DeleteNoteTool;
use App\Models\BrainDocument;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/mcp-vault-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath.'/TARS');
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('archives the note instead of deleting it, and follows it in the index', function () {
    $relativePath = 'TARS/2026-07-27-carreleur.md';
    File::put($this->vaultPath.'/'.$relativePath, "# Carreleur\n\nLe carreleur passe mardi.");
    $document = BrainDocument::factory()->create(['path' => $relativePath, 'title' => 'Carreleur']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once()->with(
        Mockery::type('string'),
        ['Archives/TARS/2026-07-27-carreleur.md', $relativePath],
        'hermes: archive TARS/2026-07-27-carreleur.md',
    ));

    TarsServer::tool(DeleteNoteTool::class, ['note' => 'carreleur'])
        ->assertOk()
        ->assertSee('archivée dans Archives/TARS/');

    $archived = File::get($this->vaultPath.'/Archives/TARS/2026-07-27-carreleur.md');

    // Nothing is lost: the body survives and the date of archiving is recorded.
    expect(File::exists($this->vaultPath.'/'.$relativePath))->toBeFalse()
        ->and($archived)->toContain('Le carreleur passe mardi')
        ->and($archived)->toContain('archived: ')
        ->and($document->fresh()->path)->toBe('Archives/TARS/2026-07-27-carreleur.md');
});

test('says so when the note has already left the vault', function () {
    BrainDocument::factory()->create(['path' => 'TARS/disparue.md', 'title' => 'Disparue']);

    mockVaultGit();

    TarsServer::tool(DeleteNoteTool::class, ['note' => 'disparue'])->assertHasErrors();
});

test('fails cleanly when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    TarsServer::tool(DeleteNoteTool::class, ['note' => 'carreleur'])->assertHasErrors(['vault']);
});

test('fails without deleting anything when the note is not found', function () {
    TarsServer::tool(DeleteNoteTool::class, ['note' => 'introuvable'])->assertHasErrors(['Aucune note']);
});
