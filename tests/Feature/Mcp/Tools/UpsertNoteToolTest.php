<?php

use App\Enums\McpCallStatus;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\UpsertNoteTool;
use App\Models\BrainDocument;
use App\Models\Entity;
use App\Models\McpCall;
use App\Support\Brain\GitPullResult;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/upsert-note-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

test('creates a person note at the path TARS computes, with a v3 frontmatter', function () {
    Entity::factory()->create(['name' => 'SARL Alpha']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once()->with(
        Mockery::type('string'),
        ['Personnes/marc-dupont.md'],
        'hermes: person Marc Dupont',
    ));

    TarsServer::tool(UpsertNoteTool::class, [
        'type' => 'person',
        'title' => 'Marc Dupont',
        'content' => 'Expert-comptable, cabinet Alpha.',
        'entity' => ['SARL Alpha'],
    ])
        ->assertOk()
        ->assertSee('créée dans Personnes/');

    $file = File::get($this->vaultPath.'/Personnes/marc-dupont.md');

    expect($file)->toContain('schema: 3')
        ->toContain('type: person')
        ->toContain("title: 'Marc Dupont'")
        ->toContain('source: hermes')
        ->toContain('Expert-comptable');

    $document = BrainDocument::where('path', 'Personnes/marc-dupont.md')->firstOrFail();

    expect($document->is_conformant)->toBeTrue()
        ->and($document->note_type)->toBe('person')
        ->and($document->entities)->toHaveCount(1);

    expect(McpCall::where('tool', 'upsert_note')->first()->status)->toBe(McpCallStatus::Success);
});

test('a second call on the same living note updates it instead of duplicating it', function () {
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));

    TarsServer::tool(UpsertNoteTool::class, [
        'type' => 'person',
        'title' => 'Marc Dupont',
        'content' => 'Expert-comptable.',
        'date' => '10/10',
    ])->assertOk();

    TarsServer::tool(UpsertNoteTool::class, [
        'type' => 'person',
        'title' => 'Marc Dupont',
        'content' => 'Expert-comptable, cabinet Alpha. Rendez-vous annuel en mars.',
    ])
        ->assertOk()
        ->assertSee('mise à jour dans Personnes/');

    expect(File::files($this->vaultPath.'/Personnes'))->toHaveCount(1)
        ->and(BrainDocument::where('path', 'like', 'Personnes/%')->count())->toBe(1);

    $file = File::get($this->vaultPath.'/Personnes/marc-dupont.md');

    // The new content replaces the body rather than being appended to it.
    expect(substr_count($file, 'Expert-comptable'))->toBe(1);

    // The original date survives; only `updated` moves.
    expect($file)->toContain('Rendez-vous annuel')
        ->and($file)->toContain("date: '".now()->setMonth(10)->setDay(10)->toDateString()."'")
        ->and($file)->toContain("updated: '".now()->toDateString()."'");
});

test('stacks two dated notes written the same day instead of overwriting', function () {
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));

    foreach (['Premier échange', 'Premier échange'] as $title) {
        TarsServer::tool(UpsertNoteTool::class, [
            'type' => 'meeting',
            'title' => $title,
            'content' => 'Compte-rendu.',
        ])->assertOk();
    }

    expect(File::files($this->vaultPath.'/Réunions'))->toHaveCount(2);
});

test('refuses a type that is not Hermes to write', function (string $type) {
    TarsServer::tool(UpsertNoteTool::class, [
        'type' => $type,
        'title' => 'Essai',
        'content' => 'Corps.',
    ])->assertHasErrors();
})->with(['profil', 'archive', 'skill']);

test('requires a status for the types that carry one', function () {
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));

    TarsServer::tool(UpsertNoteTool::class, [
        'type' => 'veille',
        'title' => 'Agents IA',
        'content' => 'Trouvaille du jour.',
        'status' => 'active',
        'subject' => 'Agents IA',
    ])->assertOk();

    expect(File::exists($this->vaultPath.'/Veille/agents-ia/'.now()->toDateString().'-agents-ia.md'))->toBeTrue();
});

test('tells Hermes the content is in the sas when the vault is diverged', function () {
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('isRepository')->andReturn(true);
        $mock->shouldReceive('pullRebase')->andReturn(new GitPullResult(successful: false, message: 'CONFLICT (content)'));
        // Only the sas note is committed.
        $mock->shouldReceive('commit')->once()->with(Mockery::type('string'), Mockery::pattern('/^TARS\//'), Mockery::type('string'));
    });

    TarsServer::tool(UpsertNoteTool::class, [
        'type' => 'person',
        'title' => 'Marc Dupont',
        'content' => 'Expert-comptable.',
    ])->assertHasErrors();

    expect(File::exists($this->vaultPath.'/Personnes/marc-dupont.md'))->toBeFalse()
        ->and(File::glob($this->vaultPath.'/TARS/*-conflit-*.md'))->toHaveCount(1);
});

test('fails cleanly when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    TarsServer::tool(UpsertNoteTool::class, [
        'type' => 'note',
        'title' => 'Essai',
        'content' => 'Corps.',
    ])->assertHasErrors(['vault']);

    expect(BrainDocument::count())->toBe(0);
});
