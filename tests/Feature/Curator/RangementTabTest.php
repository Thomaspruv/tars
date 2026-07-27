<?php

use App\Enums\BrainSuggestionStatus;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Models\Task;
use App\Models\User;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/brain-vault-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

test('shows pending suggestions in the rangement tab with a reason and detail', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);
    BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'anchor',
        'reason' => 'Cette note parle du plombier de l\'appart Lilas.',
    ]);

    Livewire::test('pages::brain')
        ->call('switchTab', 'rangement')
        ->assertSee('Cette note parle du plombier')
        ->assertSee('Notes/plombier.md');
});

test('accepts an anchor suggestion, applies it, and removes it from the pending list', function () {
    $this->actingAs(User::factory()->create());

    File::put($this->vaultPath.'/Notes/plombier.md', "---\ntype: note\n---\n\nLe plombier passe mardi.");
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md', 'frontmatter' => ['type' => 'note']]);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commit')->once());

    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'anchor',
        'frontmatter_patch' => ['entity' => 'Appart Lilas'],
    ]);

    Livewire::test('pages::brain')
        ->call('switchTab', 'rangement')
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
        'frontmatter_patch' => ['title' => 'Nouvel objectif'],
    ]);

    Livewire::test('pages::brain')
        ->call('switchTab', 'rangement')
        ->call('acceptSuggestion', $suggestion->id)
        ->assertHasErrors(['acceptSuggestion']);

    expect($suggestion->fresh()->status)->toBe(BrainSuggestionStatus::Pending);
});

test('rejects a suggestion', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);
    $suggestion = BrainSuggestion::factory()->create(['brain_document_id' => $document->id]);

    Livewire::test('pages::brain')
        ->call('switchTab', 'rangement')
        ->call('rejectSuggestion', $suggestion->id);

    expect($suggestion->fresh()->status)->toBe(BrainSuggestionStatus::Rejected);
});

test('deprioritizes a suggestion on plus tard without changing its status', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);
    $suggestion = BrainSuggestion::factory()->create(['brain_document_id' => $document->id]);

    Livewire::test('pages::brain')
        ->call('switchTab', 'rangement')
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
        ->call('switchTab', 'rangement')
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
        ->call('switchTab', 'rangement')
        ->assertDontSee('Vieille action.');
});

test('shows the sidebar badge count matching pending suggestions', function () {
    $this->actingAs(User::factory()->create());

    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);
    BrainSuggestion::factory()->count(2)->create(['brain_document_id' => $document->id]);

    $response = $this->get(route('brain.index'));

    expect($response->getContent())->toMatch('/Cerveau<\/span>\s*<span[^>]*>2<\/span>/');
});
