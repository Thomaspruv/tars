<?php

use App\Models\BrainDocument;
use App\Models\Setting;
use App\Support\Brain\BrainHealth;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\NotePathResolver;
use App\Support\Brain\Schema\FrontmatterBuilder;
use App\Support\Brain\Schema\NoteType;
use App\Support\Brain\VaultLayout;
use App\Support\Curator\CuratorContextBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Le cerveau est un dépôt unique partagé par plusieurs applications (décision
 * du 23/10/2026) et sa disposition lui appartient — elle est antérieure à
 * Tars. Elle est donc une donnée, et Tars écrit dans les dossiers du vault au
 * lieu d'en créer de concurrents à côté.
 */
beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/vault-layout-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

test('without configuration, the ontology default applies', function () {
    expect(app(VaultLayout::class)->folderFor(NoteType::Person))->toBe('Personnes')
        ->and(app(VaultLayout::class)->folderFor(NoteType::Journal))->toBe('Journal');
});

test('a configured layout wins, and TARS writes where the vault wants it', function () {
    app(BrainSettings::class)->setFolders([
        'person' => '09 - GENS',
        'journal' => '11 - JOURNAL',
        'project' => '01 - PROJETS',
        'veille' => '12 - VEILLE',
    ]);

    $layout = app(VaultLayout::class);
    $resolver = app(NotePathResolver::class);

    expect($layout->folderFor(NoteType::Person))->toBe('09 - GENS')
        // Un type absent de la correspondance retombe sur le défaut.
        ->and($layout->folderFor(NoteType::Note))->toBe('Notes')
        ->and($resolver->resolve(NoteType::Person, 'Marc Dupont'))->toBe('09 - GENS/marc-dupont.md')
        ->and($resolver->resolve(NoteType::Journal, 'peu importe', Carbon::parse('2026-07-30')))
        ->toBe('11 - JOURNAL/2026/2026-07-30.md')
        ->and($resolver->resolve(NoteType::Project, 'NanoBoard'))->toBe('01 - PROJETS/nanoboard/README.md')
        ->and($resolver->resolve(NoteType::Veille, 'Anthropic', Carbon::parse('2026-10-23'), ['subject' => 'agents IA']))
        ->toBe('12 - VEILLE/agents-ia/2026-10-23-anthropic.md');
});

test('a note is misplaced against the configured layout, not the default one', function () {
    app(BrainSettings::class)->setFolders(['person' => '09 - GENS']);

    $inTheRightPlace = BrainDocument::factory()->create([
        'path' => '09 - GENS/marc.md', 'note_type' => 'person', 'is_conformant' => true,
    ]);
    $inTheOldPlace = BrainDocument::factory()->create([
        'path' => 'Personnes/marc.md', 'note_type' => 'person', 'is_conformant' => true,
    ]);

    $health = app(BrainHealth::class);

    expect($health->isMisplaced($inTheRightPlace))->toBeFalse()
        ->and($health->isMisplaced($inTheOldPlace))->toBeTrue();
});

test('the folder a legacy note already lives in tells its type', function () {
    app(BrainSettings::class)->setFolders(['person' => '09 - GENS']);

    $frontmatter = app(FrontmatterBuilder::class)->upgrade(
        frontmatter: ['title' => 'Marc Dupont', 'date' => '2026-10-16'],
        relativePath: '09 - GENS/marc-dupont.md',
        fallbackTitle: 'Marc Dupont',
        source: 'thomas',
    );

    expect($frontmatter['type'])->toBe('person');
});

test('the curator only ever touches the folders this vault declares', function () {
    app(BrainSettings::class)->setFolders(['person' => '09 - GENS']);

    $builder = app(CuratorContextBuilder::class);

    expect($builder->isManagedPath('09 - GENS/marc.md'))->toBeTrue()
        ->and($builder->isManagedPath('Personnes/marc.md'))->toBeFalse()
        ->and($builder->isManagedPath('.hermes/technique.md'))->toBeFalse();
});

test('an unreadable layout in the database falls back instead of breaking every write', function () {
    Setting::updateOrCreate(['key' => 'brain.folders'], ['value' => 'pas du json']);

    expect(app(VaultLayout::class)->folderFor(NoteType::Person))->toBe('Personnes');
});
