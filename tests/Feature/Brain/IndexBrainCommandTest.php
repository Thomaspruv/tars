<?php

use App\Models\BrainDocument;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/brain-vault-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('indexes markdown files found in the vault', function () {
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");

    $this->artisan('brain:index')->assertExitCode(0);

    expect(BrainDocument::where('path', 'note.md')->exists())->toBeTrue();
});

test('does not duplicate documents when indexed twice', function () {
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");

    $this->artisan('brain:index');
    $this->artisan('brain:index');

    expect(BrainDocument::where('path', 'note.md')->count())->toBe(1);
});

test('updates a document when its content changes', function () {
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");
    $this->artisan('brain:index');

    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu modifié.");
    $this->artisan('brain:index');

    expect(BrainDocument::where('path', 'note.md')->value('content'))->toContain('modifié');
});

test('removes documents whose file has disappeared', function () {
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");
    $this->artisan('brain:index');

    File::delete($this->vaultPath.'/note.md');
    $this->artisan('brain:index');

    expect(BrainDocument::where('path', 'note.md')->exists())->toBeFalse();
});

test('--fresh rebuilds the index from scratch', function () {
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");
    $this->artisan('brain:index');

    BrainDocument::factory()->create(['path' => 'orphan.md']);

    $this->artisan('brain:index --fresh');

    expect(BrainDocument::where('path', 'orphan.md')->exists())->toBeFalse()
        ->and(BrainDocument::where('path', 'note.md')->exists())->toBeTrue();
});

test('does nothing when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    $this->artisan('brain:index')->assertExitCode(0);

    expect(BrainDocument::count())->toBe(0);
});

test('scheduled runs stay quiet when auto-index is disabled', function () {
    config(['brain.auto_index' => false]);
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");

    $this->artisan('brain:index --scheduled');

    expect(BrainDocument::count())->toBe(0);
});
