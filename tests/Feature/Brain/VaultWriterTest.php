<?php

use App\Enums\VaultWriteStatus;
use App\Jobs\PushVaultJob;
use App\Models\BrainDocument;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\NoteArchiver;
use App\Support\Brain\Schema\FrontmatterBuilder;
use App\Support\Brain\Schema\NoteType;
use App\Support\Brain\VaultOperation;
use App\Support\Brain\VaultWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Builds a real local remote plus a clone, so the pull-rebase path is
 * exercised against git itself — no network, no fake.
 */
function initVaultRepository(string $root): array
{
    $remote = "{$root}/remote.git";
    $vault = "{$root}/vault";
    $other = "{$root}/other";

    File::ensureDirectoryExists($root);
    Process::run(['git', 'init', '--bare', '-b', 'main', $remote])->throw();

    foreach ([$other, $vault] as $clone) {
        Process::run(['git', 'clone', $remote, $clone])->throw();
        Process::path($clone)->run(['git', 'config', 'user.email', 'tars@example.com'])->throw();
        Process::path($clone)->run(['git', 'config', 'user.name', 'TARS'])->throw();
    }

    // A first shared commit, so both clones have a common ancestor.
    File::ensureDirectoryExists("{$other}/Notes");
    File::put("{$other}/Notes/alpha.md", "---\nschema: 3\ntype: note\ntitle: Alpha\ndate: 2026-10-16\n---\n\nOrigine.\n");
    Process::path($other)->run(['git', 'add', '-A'])->throw();
    Process::path($other)->run(['git', 'commit', '-m', 'init'])->throw();
    Process::path($other)->run(['git', 'push', 'origin', 'main'])->throw();
    Process::path($vault)->run(['git', 'pull'])->throw();

    return [$vault, $other];
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/vault-writer-'.uniqid();
    [$this->vaultPath, $this->otherPath] = initVaultRepository($this->root);

    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => $this->root.'/remote.git']);

    $this->frontmatter = fn (array $extra = []) => app(FrontmatterBuilder::class)->build(
        type: NoteType::Person,
        title: 'Marc Dupont',
        date: now(),
        source: 'hermes',
        extra: $extra,
    );
});

afterEach(fn () => File::deleteDirectory($this->root));

test('writes the file, commits it with the source prefix, reindexes it and queues the push', function () {
    Queue::fake();

    $result = app(VaultWriter::class)->write(
        'Personnes/marc-dupont.md',
        ($this->frontmatter)(),
        "Expert-comptable, cabinet Alpha.\n",
        'person Marc Dupont',
    );

    expect($result->isWritten())->toBeTrue()
        ->and($result->path())->toBe('Personnes/marc-dupont.md')
        ->and(File::get($this->vaultPath.'/Personnes/marc-dupont.md'))->toContain('schema: 3')
        ->and(BrainDocument::where('path', 'Personnes/marc-dupont.md')->first()->is_conformant)->toBeTrue();

    $log = Process::path($this->vaultPath)->run(['git', 'log', '-1', '--format=%s'])->output();
    expect(trim($log))->toBe('hermes: person Marc Dupont');

    Queue::assertPushed(PushVaultJob::class);
});

test('refuses a machine write whose frontmatter is not conformant', function () {
    Queue::fake();

    $result = app(VaultWriter::class)->write('Personnes/marc.md', ['type' => 'person'], 'Corps.', 'person Marc');

    expect($result->status)->toBe(VaultWriteStatus::Invalid)
        ->and($result->message())->toContain('non conforme')
        ->and(File::exists($this->vaultPath.'/Personnes/marc.md'))->toBeFalse();

    Queue::assertNothingPushed();
});

test('writes Thomas own text verbatim, without holding it to the contract', function () {
    Queue::fake();

    $raw = "---\ntype: insight\n---\n\n# Brut de pomme\n";
    $result = app(VaultWriter::class)->writeRaw('Notes/brut.md', $raw, 'edit Notes/brut.md');

    expect($result->isWritten())->toBeTrue()
        ->and(File::get($this->vaultPath.'/Notes/brut.md'))->toBe($raw)
        ->and(BrainDocument::where('path', 'Notes/brut.md')->first()->is_conformant)->toBeFalse();

    expect(trim(Process::path($this->vaultPath)->run(['git', 'log', '-1', '--format=%s'])->output()))
        ->toBe('tars: edit Notes/brut.md');
});

test('writes a batch of operations in a single commit', function () {
    Queue::fake();

    $result = app(VaultWriter::class)->apply([
        VaultOperation::write('Personnes/marc-dupont.md', ($this->frontmatter)(), 'Un.'),
        VaultOperation::write('Personnes/lea-martin.md', ($this->frontmatter)(), 'Deux.'),
    ], 'index');

    expect($result->paths)->toHaveCount(2);

    $count = Process::path($this->vaultPath)->run(['git', 'rev-list', '--count', 'HEAD'])->output();
    expect((int) trim($count))->toBe(2);
});

test('a move deletes the source file and its index entry', function () {
    Queue::fake();
    app(VaultWriter::class)->write('Notes/beta.md', ($this->frontmatter)(), 'Corps.', 'note beta');

    app(VaultWriter::class)->move('Notes/beta.md', 'Personnes/beta.md', ($this->frontmatter)(), 'Corps.', 'move');

    expect(File::exists($this->vaultPath.'/Notes/beta.md'))->toBeFalse()
        ->and(File::exists($this->vaultPath.'/Personnes/beta.md'))->toBeTrue()
        ->and(BrainDocument::where('path', 'Notes/beta.md')->exists())->toBeFalse()
        ->and(BrainDocument::where('path', 'Personnes/beta.md')->exists())->toBeTrue();
});

test('a divergent vault loses nothing: the content is parked in the sas and an alert is raised', function () {
    Queue::fake();

    // Thomas edited Notes/alpha.md in Obsidian and pushed…
    File::put($this->otherPath.'/Notes/alpha.md', "---\nschema: 3\ntype: note\ntitle: Alpha\ndate: 2026-10-16\n---\n\nVersion de Thomas.\n");
    Process::path($this->otherPath)->run(['git', 'commit', '-am', 'thomas'])->throw();
    Process::path($this->otherPath)->run(['git', 'push'])->throw();

    // …while TARS had already committed its own version of the same line.
    File::put($this->vaultPath.'/Notes/alpha.md', "---\nschema: 3\ntype: note\ntitle: Alpha\ndate: 2026-10-16\n---\n\nVersion de TARS.\n");
    Process::path($this->vaultPath)->run(['git', 'commit', '-am', 'tars: local'])->throw();

    $result = app(VaultWriter::class)->write(
        'Notes/alpha.md',
        ($this->frontmatter)(),
        "Une troisième version, écrite par Hermes.\n",
        'note alpha',
    );

    expect($result->status)->toBe(VaultWriteStatus::Conflict)
        ->and($result->message())->toContain('gardé le contenu dans le sas');

    // Nothing was overwritten, and no rebase was left half-done.
    expect(File::get($this->vaultPath.'/Notes/alpha.md'))->toContain('Version de TARS')
        ->and(File::isDirectory($this->vaultPath.'/.git/rebase-merge'))->toBeFalse()
        ->and(File::isDirectory($this->vaultPath.'/.git/rebase-apply'))->toBeFalse();

    // The content is in the sas, as an a-traiter note flagged conflict.
    $sasFile = $result->sasPath;
    expect($sasFile)->toContain('-conflit-')
        ->and(File::get($this->vaultPath.'/'.$sasFile))->toContain('écrite par Hermes')
        ->and(File::get($this->vaultPath.'/'.$sasFile))->toContain('conflict: true')
        ->and(File::get($this->vaultPath.'/'.$sasFile))->toContain('type: a-traiter');

    // And Thomas is told, in Réglages → Cerveau.
    expect(app(BrainSettings::class)->lastConflictAt())->not->toBeNull()
        ->and(app(BrainSettings::class)->lastConflictMessage())->toContain($sasFile);

    Queue::assertNothingPushed();
});

test('a successful write clears a previous conflict alert', function () {
    Queue::fake();
    app(BrainSettings::class)->recordConflict('Un vieux conflit.');

    app(VaultWriter::class)->write('Personnes/marc-dupont.md', ($this->frontmatter)(), 'Corps.', 'person Marc');

    expect(app(BrainSettings::class)->lastConflictAt())->toBeNull();
});

test('does nothing at all when the vault is not configured', function () {
    Queue::fake();
    config(['brain.remote_url' => null]);

    $result = app(VaultWriter::class)->write('Personnes/marc.md', ($this->frontmatter)(), 'Corps.', 'person Marc');

    expect($result->status)->toBe(VaultWriteStatus::Unconfigured)
        ->and(File::exists($this->vaultPath.'/Personnes/marc.md'))->toBeFalse();
});

test('refuses a path that would leave the vault', function (string $path) {
    Queue::fake();

    $result = app(VaultWriter::class)->write($path, ($this->frontmatter)(), 'Corps.', 'person Marc');

    expect($result->status)->toBe(VaultWriteStatus::Invalid)
        ->and($result->message())->toContain('hors du vault');

    Queue::assertNothingPushed();
})->with([
    'remontée' => ['../marc.md'],
    'remontée profonde' => ['Personnes/../../marc.md'],
    'chemin absolu' => ['/tmp/marc.md'],
    'chemin vide' => [''],
]);

test('refuses a move whose source would leave the vault', function () {
    Queue::fake();

    $result = app(VaultWriter::class)->move('../ailleurs.md', 'Personnes/marc.md', ($this->frontmatter)(), 'Corps.', 'move');

    expect($result->status)->toBe(VaultWriteStatus::Invalid);
});

test('archiving keeps the origin folder so two notes cannot collide', function () {
    Queue::fake();

    File::ensureDirectoryExists($this->vaultPath.'/Personnes');
    File::put($this->vaultPath.'/Notes/homonyme.md', "---\nschema: 3\ntype: note\ntitle: Homonyme\ndate: 2026-10-16\n---\n\nLa note.\n");
    File::put($this->vaultPath.'/Personnes/homonyme.md', "---\nschema: 3\ntype: person\ntitle: Homonyme\ndate: 2026-10-16\n---\n\nLa personne.\n");

    $archiver = app(NoteArchiver::class);
    $archiver->archive('Notes/homonyme.md', 'hermes', 'archive Notes/homonyme.md');
    $archiver->archive('Personnes/homonyme.md', 'hermes', 'archive Personnes/homonyme.md');

    expect(File::get($this->vaultPath.'/Archives/Notes/homonyme.md'))->toContain('La note.')
        ->and(File::get($this->vaultPath.'/Archives/Personnes/homonyme.md'))->toContain('La personne.');
});

test('archiving a note that has already left the vault says so', function () {
    expect(fn () => app(NoteArchiver::class)->archive('Notes/fantome.md', 'hermes', 'archive'))
        ->toThrow(RuntimeException::class, "n'est plus dans le vault");
});
