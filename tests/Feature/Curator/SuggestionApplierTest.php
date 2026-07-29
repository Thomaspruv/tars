<?php

use App\Enums\BrainSuggestionStatus;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\Task;
use App\Support\Brain\GitRepository;
use App\Support\Curator\SuggestionApplier;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/applier-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

test('applies an anchor suggestion by patching frontmatter and committing', function () {
    File::put($this->vaultPath.'/Notes/plombier.md', "---\ntype: note\n---\n\nLe plombier passe mardi.");
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md', 'frontmatter' => ['type' => 'note']]);

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('commit')->once()->with(Mockery::type('string'), 'Notes/plombier.md', Mockery::type('string'));
    });

    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'anchor',
        'frontmatter_patch' => ['entity' => 'Appart Lilas'],
    ]);

    app(SuggestionApplier::class)->apply($suggestion);

    $content = File::get($this->vaultPath.'/Notes/plombier.md');
    expect($content)->toContain('Appart Lilas')
        ->and($suggestion->fresh()->status)->toBe(BrainSuggestionStatus::Accepted)
        ->and(BrainDocument::where('path', 'Notes/plombier.md')->first()->frontmatter['entity'])->toBe('Appart Lilas');
});

test('applies a move suggestion by renaming the file and reindexing under the new path', function () {
    File::put($this->vaultPath.'/Notes/plombier.md', "---\ntype: note\n---\n\nLe plombier passe mardi.");
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commitAll')->once());

    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'move',
        'target' => 'Archives/plombier.md',
    ]);

    app(SuggestionApplier::class)->apply($suggestion);

    expect(File::exists($this->vaultPath.'/Notes/plombier.md'))->toBeFalse()
        ->and(File::exists($this->vaultPath.'/Archives/plombier.md'))->toBeTrue()
        ->and(BrainDocument::where('path', 'Notes/plombier.md')->exists())->toBeFalse()
        ->and(BrainDocument::where('path', 'Archives/plombier.md')->exists())->toBeTrue();
});

test('fails a move suggestion with no target and leaves the suggestion pending', function () {
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);
    $suggestion = BrainSuggestion::factory()->create(['brain_document_id' => $document->id, 'action' => 'move', 'target' => null]);

    expect(fn () => app(SuggestionApplier::class)->apply($suggestion))->toThrow(RuntimeException::class);
    expect($suggestion->fresh()->status)->toBe(BrainSuggestionStatus::Pending);
});

test('applies a merge suggestion writing the merged content and archiving the source without deleting it', function () {
    File::put($this->vaultPath.'/Notes/plombier.md', "---\ntype: note\n---\n\nAncien contenu.");
    File::put($this->vaultPath.'/Notes/appart-lilas.md', "---\ntype: note\n---\n\nAppart Lilas.");
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commitAll')->once());

    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'merge',
        'target' => 'Notes/appart-lilas.md',
        'merged_content' => "---\ntype: note\n---\n\nAppart Lilas, avec le plombier.",
    ]);

    app(SuggestionApplier::class)->apply($suggestion);

    expect(File::get($this->vaultPath.'/Notes/appart-lilas.md'))->toContain('avec le plombier')
        ->and(File::exists($this->vaultPath.'/Archives/plombier.md'))->toBeTrue()
        ->and(File::exists($this->vaultPath.'/Notes/plombier.md'))->toBeFalse();
});

test('applies an archive suggestion by moving the file to Archives with an archived date', function () {
    File::put($this->vaultPath.'/Notes/plombier.md', "---\ntype: note\n---\n\nPérimé.");
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commitAll')->once());

    $suggestion = BrainSuggestion::factory()->create(['brain_document_id' => $document->id, 'action' => 'archive']);

    app(SuggestionApplier::class)->apply($suggestion);

    $archived = File::get($this->vaultPath.'/Archives/plombier.md');
    expect($archived)->toContain('archived:')
        ->and(File::exists($this->vaultPath.'/Notes/plombier.md'))->toBeFalse();
});

test('applies a create_task suggestion and resolves the entity by name', function () {
    $entity = Entity::factory()->create(['name' => 'Appart Lilas']);
    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md']);

    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'create_task',
        'frontmatter_patch' => ['title' => 'Rappeler Marc', 'entity' => 'Appart Lilas'],
    ]);

    app(SuggestionApplier::class)->apply($suggestion);

    $task = Task::where('title', 'Rappeler Marc')->firstOrFail();
    expect($task->entity_id)->toBe($entity->id)
        ->and($suggestion->fresh()->created_id)->toBe($task->id);
});

test('fails a create_task suggestion with no title', function () {
    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md']);
    $suggestion = BrainSuggestion::factory()->create(['brain_document_id' => $document->id, 'action' => 'create_task', 'frontmatter_patch' => []]);

    expect(fn () => app(SuggestionApplier::class)->apply($suggestion))->toThrow(RuntimeException::class);
});

test('applies a create_list_item suggestion into the matching list', function () {
    $checklist = Checklist::factory()->create(['name' => 'Courses']);
    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md']);

    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'create_list_item',
        'target' => 'Courses',
        'frontmatter_patch' => ['content' => 'Lait'],
    ]);

    app(SuggestionApplier::class)->apply($suggestion);

    $item = ChecklistItem::where('content', 'Lait')->firstOrFail();
    expect($item->list_id)->toBe($checklist->id)
        ->and($suggestion->fresh()->created_id)->toBe($item->id);
});

test('fails a create_list_item suggestion when the list is not found', function () {
    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md']);
    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'create_list_item',
        'target' => 'Introuvable',
        'frontmatter_patch' => ['content' => 'Lait'],
    ]);

    expect(fn () => app(SuggestionApplier::class)->apply($suggestion))->toThrow(RuntimeException::class);
});

test('applies a create_goal suggestion and resolves the entity by name', function () {
    $entity = Entity::factory()->create(['name' => 'Appart Lilas']);
    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md']);

    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'create_goal',
        'frontmatter_patch' => ['title' => 'Vendre l\'appart', 'entity' => 'Appart Lilas'],
    ]);

    app(SuggestionApplier::class)->apply($suggestion);

    $goal = Goal::where('title', "Vendre l'appart")->firstOrFail();
    expect($goal->entity_id)->toBe($entity->id)
        ->and($suggestion->fresh()->created_id)->toBe($goal->id);
});

test('fails a create_goal suggestion with no title', function () {
    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md']);
    $suggestion = BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'create_goal',
        'frontmatter_patch' => [],
    ]);

    expect(fn () => app(SuggestionApplier::class)->apply($suggestion))->toThrow(RuntimeException::class);
    expect(Goal::count())->toBe(0);
});
