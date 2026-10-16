<?php

use App\Models\BrainDocument;
use App\Models\Entity;
use App\Support\Brain\VaultIndexer;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/brain-conformance-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);

    $this->entity = Entity::factory()->create(['name' => 'SARL Alpha']);
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

function writeVaultFile(string $relativePath, string $content): void
{
    $absolute = test()->vaultPath.'/'.$relativePath;
    File::ensureDirectoryExists(dirname($absolute));
    File::put($absolute, $content);
}

test('records the schema version, type and status of a conformant note', function () {
    writeVaultFile('Réunions/2026-10-16-point-alpha.md', <<<'MD'
        ---
        schema: 3
        type: meeting
        title: Point Alpha
        date: 2026-10-16
        updated: 2026-10-17
        entity: SARL Alpha
        source: hermes
        ---
        Compte-rendu.
        MD);

    app(VaultIndexer::class)->indexAll();

    $document = BrainDocument::where('path', 'Réunions/2026-10-16-point-alpha.md')->firstOrFail();

    expect($document->is_conformant)->toBeTrue()
        ->and($document->schema_version)->toBe(3)
        ->and($document->note_type)->toBe('meeting')
        ->and($document->updated_on->toDateString())->toBe('2026-10-17')
        ->and($document->entities)->toHaveCount(1);
});

test('does not resolve the anchors of a non conformant note', function () {
    writeVaultFile('Notes/alpha.md', <<<'MD'
        ---
        type: insight
        entity: SARL Alpha
        ---
        Une note héritée de la v2.
        MD);

    app(VaultIndexer::class)->indexAll();

    $document = BrainDocument::where('path', 'Notes/alpha.md')->firstOrFail();

    expect($document->is_conformant)->toBeFalse()
        ->and($document->entities)->toHaveCount(0)
        ->and($document->content)->toContain('héritée');
});

test('drops the anchors of a note that loses its conformance', function () {
    writeVaultFile('Notes/alpha.md', "---\nschema: 3\ntype: note\ntitle: Alpha\ndate: 2026-10-16\nentity: SARL Alpha\n---\nÉtat du dossier.");
    app(VaultIndexer::class)->indexAll();

    expect(BrainDocument::where('path', 'Notes/alpha.md')->firstOrFail()->entities)->toHaveCount(1);

    writeVaultFile('Notes/alpha.md', "---\ntype: note\nentity: SARL Alpha\n---\nÉtat du dossier, remanié.");
    app(VaultIndexer::class)->indexAll();

    $document = BrainDocument::where('path', 'Notes/alpha.md')->firstOrFail();

    expect($document->is_conformant)->toBeFalse()
        ->and($document->fresh()->entities)->toHaveCount(0);
});

test('keeps anchoring a Profil note that carries no schema', function () {
    writeVaultFile('Profil/pro.md', "---\ntype: profil\nentity: SARL Alpha\n---\nMon activité.");

    app(VaultIndexer::class)->indexAll();

    $document = BrainDocument::where('path', 'Profil/pro.md')->firstOrFail();

    expect($document->is_conformant)->toBeFalse()
        ->and($document->isConformanceExempt())->toBeTrue()
        ->and($document->entities)->toHaveCount(1);
});

test('indexes and anchors Hermes own memory territory', function () {
    writeVaultFile('.hermes/active-context.md', "---\ntype: context\nentity: SARL Alpha\n---\nContexte technique.");

    app(VaultIndexer::class)->indexAll();

    $document = BrainDocument::where('path', '.hermes/active-context.md')->firstOrFail();

    expect($document->entities)->toHaveCount(1)
        ->and($document->isConformanceExempt())->toBeTrue();
});

test('never indexes the other dot folders', function () {
    writeVaultFile('.obsidian/workspace.md', '# Interne');
    writeVaultFile('.git/COMMIT_EDITMSG.md', '# Interne');

    app(VaultIndexer::class)->indexAll();

    expect(BrainDocument::count())->toBe(0);
});

test('under-contract scope excludes the exempt zones and the generated files', function () {
    BrainDocument::factory()->create(['path' => 'Notes/alpha.md']);
    BrainDocument::factory()->create(['path' => 'Profil/perso.md']);
    BrainDocument::factory()->create(['path' => '.hermes/rules.md']);
    BrainDocument::factory()->create(['path' => 'README.md']);
    BrainDocument::factory()->create(['path' => 'Personnes/_index.md']);

    expect(BrainDocument::underContract()->pluck('path')->all())->toBe(['Notes/alpha.md']);
});

test('a note whose name merely ends in index is still under contract', function () {
    BrainDocument::factory()->create(['path' => 'Notes/myindex.md']);
    BrainDocument::factory()->create(['path' => 'Notes/_index.md']);

    expect(BrainDocument::underContract()->pluck('path')->all())->toBe(['Notes/myindex.md']);
});
