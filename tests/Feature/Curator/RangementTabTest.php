<?php

use App\Enums\BrainSuggestionStatus;
use App\Enums\GitSyncStatus;
use App\Jobs\RunAgentCommandJob;
use App\Models\AgentConfig;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Models\Task;
use App\Models\User;
use App\Support\Brain\BrainSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/brain-vault-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

test('shows pending suggestions in the curator column with their reason and path', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);
    BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'anchor',
        'reason' => 'Cette note parle du plombier de l\'appart Lilas.',
    ]);

    Livewire::test('pages::brain')
        ->assertSee('Cette note parle du plombier')
        ->assertSee('Notes/plombier.md');
});

test('accepts an anchor suggestion, applies it, and removes it from the pending list', function () {
    $this->actingAs(User::factory()->create());

    File::put($this->vaultPath.'/Notes/plombier.md', "---\ntype: note\n---\n\nLe plombier passe mardi.");
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md', 'frontmatter' => ['type' => 'note']]);

    mockVaultGit(function ($mock) {
        $mock->shouldReceive('commitPaths')->once();
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Synced);
    });

    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'anchor',
        'frontmatter_patch' => ['entity' => 'Appart Lilas'],
    ]);

    Livewire::test('pages::brain')
        ->call('acceptSuggestion', $suggestion->id)
        ->assertHasNoErrors();

    expect($suggestion->fresh()->status)->toBe(BrainSuggestionStatus::Accepted);
});

test('shows an inline error and leaves the suggestion pending when acceptance fails', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md']);
    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'create_goal',
        'frontmatter_patch' => [],
    ]);

    Livewire::test('pages::brain')
        ->call('acceptSuggestion', $suggestion->id)
        ->assertHasErrors(['acceptSuggestion']);

    expect($suggestion->fresh()->status)->toBe(BrainSuggestionStatus::Pending);
});

test('rejects a suggestion', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);
    $suggestion = BrainSuggestion::factory()->create(['brain_document_id' => $document->id]);

    Livewire::test('pages::brain')
        ->call('rejectSuggestion', $suggestion->id);

    expect($suggestion->fresh()->status)->toBe(BrainSuggestionStatus::Rejected);
});

test('deprioritizes a suggestion on plus tard without changing its status', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);
    $suggestion = BrainSuggestion::factory()->create(['brain_document_id' => $document->id]);

    Livewire::test('pages::brain')
        ->call('deprioritizeSuggestion', $suggestion->id);

    $suggestion->refresh();
    expect($suggestion->status)->toBe(BrainSuggestionStatus::Pending)
        ->and($suggestion->deprioritized_at)->not->toBeNull();
});

test('shows recently auto-applied suggestions and cancels one, deleting the created record', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md']);
    $task = Task::factory()->create(['title' => 'Rappeler Marc']);
    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'create_task',
        'status' => BrainSuggestionStatus::AutoApplied,
        'reason' => 'Action évidente.',
        'created_type' => Task::class,
        'created_id' => $task->id,
    ]);

    Livewire::test('pages::brain')
        ->assertSee('Action évidente.')
        ->call('cancelAutoApplied', $suggestion->id);

    expect($suggestion->fresh()->status)->toBe(BrainSuggestionStatus::Rejected)
        ->and(Task::find($task->id))->toBeNull();
});

test('does not show auto-applied suggestions older than 7 days', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md']);
    BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'status' => BrainSuggestionStatus::AutoApplied,
        'reason' => 'Vieille action.',
        'created_at' => now()->subDays(10),
    ]);

    Livewire::test('pages::brain')
        ->assertDontSee('Vieille action.');
});

test('shows a dot on the Mémoire space and the exact count on its Notes tab', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);
    BrainSuggestion::factory()->count(2)->create(['brain_document_id' => $document->id]);

    // Mémoire elle-même n'a plus la sidebar de l'app (elle la remplace par son
    // arborescence), donc le signal se lit depuis n'importe quel autre écran.
    // L'espace se contente d'un point — ce que le curateur a produit n'est pas
    // un chiffre qui m'attend ; le 2 vit sur l'onglet Notes, dans l'espace.
    expect($this->get(route('today'))->getContent())
        ->toMatch('/Mémoire<\/span>\s*<span[^>]*size-\[8px\][^>]*><\/span>/')
        ->and($this->get(route('entities.index'))->getContent())
        ->toContain('bg-(--aibg) text-(--ai)">2');
});

test('shows the memory health in the brain header', function () {
    $this->actingAs(User::factory()->create());
    BrainDocument::factory()->create(['path' => 'Notes/ok.md', 'note_type' => 'note', 'is_conformant' => true]);
    BrainDocument::factory()->create(['path' => 'Notes/ko.md', 'is_conformant' => false]);

    mockVaultGit();

    Livewire::test('pages::brain')
        ->assertSee('50 % conforme')
        ->assertSee('Dernier push');
});

test('shows the conflict alert and links to the force-sync button', function () {
    $this->actingAs(User::factory()->create());
    app(BrainSettings::class)->recordConflict('Contenu gardé dans TARS/2026-10-16-conflit-alpha.md.');

    mockVaultGit();

    Livewire::test('pages::brain')
        ->assertSee('Conflit git')
        ->assertSee('TARS/2026-10-16-conflit-alpha.md')
        ->assertSee('Forcer la synchro dans les Réglages');
});

test('groups conformance suggestions into a batch Thomas can accept in one click', function () {
    $this->actingAs(User::factory()->create());
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    File::put($this->vaultPath.'/Notes/marc.md', "---\ntype: insight\n---\n\nMarc Dupont.");

    $document = BrainDocument::factory()->create(['path' => 'Notes/marc.md', 'title' => 'Marc', 'frontmatter' => ['type' => 'insight']]);

    BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'batch_id' => 'lot-1',
        'action' => 'complete',
        'frontmatter_patch' => ['type' => 'person', 'title' => 'Marc Dupont', 'date' => '2026-07-26'],
        'reason' => 'Frontmatter hérité de la v2.',
    ]);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once()->with(
        Mockery::type('string'),
        ['Notes/marc.md'],
        'tars: curator conformance batch lot-1',
    ));

    Livewire::test('pages::brain')
        ->assertSee('Conformité')
        ->assertSee('Frontmatter hérité de la v2.')
        ->assertSee('Accepter tout le lot')
        ->call('acceptConformanceBatch', 'lot-1')
        ->assertHasNoErrors();

    expect(BrainSuggestion::first()->status)->toBe(BrainSuggestionStatus::Accepted)
        ->and(File::get($this->vaultPath.'/Notes/marc.md'))->toContain('type: person');
});

test('refusing a batch rejects every suggestion in it without touching the vault', function () {
    $this->actingAs(User::factory()->create());
    $document = BrainDocument::factory()->create(['path' => 'Notes/marc.md']);

    BrainSuggestion::factory()->count(2)->create(['brain_document_id' => $document->id, 'batch_id' => 'lot-1', 'action' => 'complete']);

    mockVaultGit(fn ($mock) => $mock->shouldNotReceive('commitPaths'));

    Livewire::test('pages::brain')
        ->call('rejectConformanceBatch', 'lot-1')
        ->assertDontSee('Accepter tout le lot');

    expect(BrainSuggestion::where('status', BrainSuggestionStatus::Rejected)->count())->toBe(2);
});

test('the conformance check is queued, and refuses to run without a configured curator', function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();

    mockVaultGit();

    Livewire::test('pages::brain')
        ->call('runConformanceCheck')
        ->assertHasErrors(['conformance']);

    Queue::assertNothingPushed();

    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);

    Livewire::test('pages::brain')
        ->call('runConformanceCheck')
        ->assertHasNoErrors()
        ->assertSee('Vérification lancée');

    Queue::assertPushed(RunAgentCommandJob::class);
});
