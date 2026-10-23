<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetMemoryMapTool;
use App\Models\BrainDocument;
use App\Support\Brain\IndexFileGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/memory-map-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

/**
 * La porte d'entrée est **lue**, pas écrite : le vault est un cerveau partagé,
 * son README et son AGENTS.md appartiennent au dépôt et non à TARS. C'est ce
 * qui a changé le 23/10 — TARS reposait son propre README par-dessus celui
 * d'Hermès à chaque réindexation.
 */
test('returns the front door the vault itself carries, plus the root indexes', function () {
    BrainDocument::factory()->create(['path' => 'AGENTS.md', 'title' => 'Protocole', 'content' => "# Protocole de lecture\n\nLire 00 - HOT d'abord."]);
    BrainDocument::factory()->create(['path' => 'README.md', 'title' => 'Contexte', 'content' => '# Contexte — la bibliothèque']);
    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc Dupont', 'note_type' => 'person']);
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));
    app(IndexFileGenerator::class)->write();

    TarsServer::tool(GetMemoryMapTool::class, [])
        ->assertOk()
        ->assertSee('Protocole de lecture')
        ->assertSee('Contexte — la bibliothèque')
        ->assertSee('Personnes')
        ->assertSee('Marc Dupont');
});

test('works with only the indexes when the vault has no front door of its own', function () {
    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc Dupont', 'note_type' => 'person']);
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));
    app(IndexFileGenerator::class)->write();

    TarsServer::tool(GetMemoryMapTool::class, [])->assertOk()->assertSee('Marc Dupont');
});

test('tells the agent to index when nothing has been indexed yet', function () {
    TarsServer::tool(GetMemoryMapTool::class, [])->assertHasErrors();
});

test('fails cleanly when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    TarsServer::tool(GetMemoryMapTool::class, [])->assertHasErrors(['vault']);
});

test('truncates an index that has grown into a listing', function () {
    foreach (range(1, 210) as $index) {
        BrainDocument::factory()->create([
            'path' => "Personnes/personne-{$index}.md",
            'title' => "Personne {$index}",
            'note_type' => 'person',
        ]);
    }

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));
    app(IndexFileGenerator::class)->write();

    TarsServer::tool(GetMemoryMapTool::class, [])
        ->assertOk()
        ->assertSee('utilise list_notes pour filtrer');
});
