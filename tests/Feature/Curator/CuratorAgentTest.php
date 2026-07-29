<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\BrainSuggestionStatus;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Goal;
use App\Models\Task;
use App\Support\Agents\AgentRunner;
use App\Support\Brain\GitRepository;
use App\Support\Curator\CuratorAgent;
use App\Support\Curator\CuratorContextBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/curator-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath.'/TARS');
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

test('returns null and creates no suggestion when the curator is not configured', function () {
    $run = app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    expect($run)->toBeNull()
        ->and(BrainSuggestion::count())->toBe(0);
});

test('marks the run failed and writes nothing when the json is invalid', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => 'not json at all']));
    });

    $run = app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and(BrainSuggestion::count())->toBe(0);
});

test('marks the run failed when an item is missing required fields', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([['path' => 'Notes/x.md']])]));
    });

    $run = app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and(BrainSuggestion::count())->toBe(0);
});

test('creates a pending suggestion for a valid anchor action', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'Notes/plombier.md',
            'action' => 'anchor',
            'frontmatter' => ['entity' => 'Appart Lilas'],
            'confidence' => 'medium',
            'reason' => 'Note liée au plombier de l\'appart Lilas.',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    $suggestion = BrainSuggestion::firstOrFail();
    expect($suggestion->brain_document_id)->toBe($document->id)
        ->and($suggestion->status)->toBe(BrainSuggestionStatus::Pending);
});

test('auto-applies an anchor action at high confidence', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    File::put($this->vaultPath.'/Notes/plombier.md', "---\ntype: note\n---\n\nLe plombier passe mardi.");
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md', 'frontmatter' => ['type' => 'note']]);

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('commit')->once()->with(Mockery::type('string'), 'Notes/plombier.md', Mockery::type('string'));
    });

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'Notes/plombier.md',
            'action' => 'anchor',
            'frontmatter' => ['entity' => 'Appart Lilas'],
            'confidence' => 'high',
            'reason' => 'Note liée au plombier de l\'appart Lilas.',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    $suggestion = BrainSuggestion::firstOrFail();
    expect($suggestion->brain_document_id)->toBe($document->id)
        ->and($suggestion->status)->toBe(BrainSuggestionStatus::AutoApplied)
        ->and(File::get($this->vaultPath.'/Notes/plombier.md'))->toContain('Appart Lilas');
});

test('auto-applies a move action at high confidence during the tidy mission', function () {
    // Unlike create_task/create_list_item/create_goal, a successful move/merge
    // deletes the source BrainDocument — which cascade-deletes the
    // BrainSuggestion row itself (brain_document_id is cascadeOnDelete). So
    // there's no suggestion row left to assert on here; the vault's git
    // history is the durable record for this action type, not the DB.
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    File::put($this->vaultPath.'/Notes/plombier.md', "---\ntype: note\n---\n\nLe plombier passe mardi.");
    BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commitAll')->once());

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'Notes/plombier.md',
            'action' => 'move',
            'target' => 'Archives/plombier.md',
            'confidence' => 'high',
            'reason' => 'Périmé.',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    expect(File::exists($this->vaultPath.'/Notes/plombier.md'))->toBeFalse()
        ->and(File::exists($this->vaultPath.'/Archives/plombier.md'))->toBeTrue()
        ->and(BrainDocument::where('path', 'Archives/plombier.md')->exists())->toBeTrue();
});

test('falls back to pending when an auto-applicable move is missing a target', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    File::put($this->vaultPath.'/Notes/plombier.md', "---\ntype: note\n---\n\nLe plombier passe mardi.");
    BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'Notes/plombier.md',
            'action' => 'move',
            'confidence' => 'high',
            'reason' => 'Périmé.',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    expect(BrainSuggestion::firstOrFail()->status)->toBe(BrainSuggestionStatus::Pending)
        ->and(File::exists($this->vaultPath.'/Notes/plombier.md'))->toBeTrue();
});

test('skips a suggestion referencing an out-of-scope path', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    BrainDocument::factory()->create(['path' => 'ActiveContext/session.md']);

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'ActiveContext/session.md',
            'action' => 'archive',
            'confidence' => 'high',
            'reason' => 'Périmé.',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    expect(BrainSuggestion::count())->toBe(0);
});

test('skips a move/merge/archive suggestion on Profil/ but allows anchor', function () {
    // Profil/ is never part of notesToProcess() (the brief explicitly excludes it
    // from the "unanchored notes" trawl), so this test mocks CuratorContextBuilder
    // directly to exercise isOutOfScope()'s Profil/ carve-out as a defense-in-depth
    // check, independent of upstream note selection.
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    $document = BrainDocument::factory()->create(['path' => 'Profil/perso.md']);

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([
            ['path' => 'Profil/perso.md', 'action' => 'archive', 'confidence' => 'high', 'reason' => 'x'],
            ['path' => 'Profil/perso.md', 'action' => 'complete', 'frontmatter' => ['date' => '2026-07-27'], 'confidence' => 'medium', 'reason' => 'y'],
        ])]));
    });

    $this->mock(CuratorContextBuilder::class, function ($mock) use ($document) {
        $mock->shouldReceive('notesToProcess')->once()->andReturn(new Collection([$document]));
        $mock->shouldReceive('build')->once()->andReturn('context');
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    expect(BrainSuggestion::count())->toBe(1)
        ->and(BrainSuggestion::first()->action->value)->toBe('complete');
});

test('does not recreate a suggestion identical to one rejected less than 30 days ago', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);

    BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'archive',
        'target' => null,
        'status' => BrainSuggestionStatus::Rejected,
        'created_at' => now()->subDays(5),
    ]);

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'Notes/plombier.md',
            'action' => 'archive',
            'confidence' => 'high',
            'reason' => 'Périmé.',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    expect(BrainSuggestion::where('status', BrainSuggestionStatus::Pending)->count())->toBe(0);
});

test('recreates a suggestion identical to one rejected more than 30 days ago', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);

    BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'action' => 'archive',
        'target' => null,
        'status' => BrainSuggestionStatus::Rejected,
        'created_at' => now()->subDays(40),
    ]);

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'Notes/plombier.md',
            'action' => 'archive',
            'confidence' => 'high',
            'reason' => 'Périmé.',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Manual, 'tidy');

    expect(BrainSuggestion::where('status', BrainSuggestionStatus::Pending)->count())->toBe(1);
});

test('auto-applies create_task at high confidence with no duplicate', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    File::put($this->vaultPath.'/TARS/note.md', "---\ntype: a-traiter\n---\n\nRappeler Marc.");
    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md', 'frontmatter' => ['type' => 'a-traiter']]);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commit')->once());

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'TARS/note.md',
            'action' => 'create_task',
            'frontmatter' => ['title' => 'Rappeler Marc'],
            'confidence' => 'high',
            'reason' => 'Action évidente.',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Scheduled, 'todo');

    $suggestion = BrainSuggestion::firstOrFail();
    expect($suggestion->status)->toBe(BrainSuggestionStatus::AutoApplied)
        ->and($suggestion->created_type)->toBe(Task::class)
        ->and(Task::where('title', 'Rappeler Marc')->exists())->toBeTrue();
});

test('falls back to pending when an auto-applicable task duplicates an open task', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    Task::factory()->create(['title' => 'Rappeler Marc', 'status' => 'todo']);
    File::put($this->vaultPath.'/TARS/note.md', "---\ntype: a-traiter\n---\n\nRappeler Marc.");
    BrainDocument::factory()->create(['path' => 'TARS/note.md', 'frontmatter' => ['type' => 'a-traiter']]);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commit')->once());

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'TARS/note.md',
            'action' => 'create_task',
            'frontmatter' => ['title' => 'Rappeler Marc'],
            'confidence' => 'high',
            'reason' => 'Action évidente.',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Scheduled, 'todo');

    $suggestion = BrainSuggestion::firstOrFail();
    expect($suggestion->status)->toBe(BrainSuggestionStatus::Pending)
        ->and(Task::where('title', 'Rappeler Marc')->count())->toBe(1);
});

test('never auto-applies create_task at medium confidence', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    File::put($this->vaultPath.'/TARS/note.md', "---\ntype: a-traiter\n---\n\nRappeler Marc.");
    BrainDocument::factory()->create(['path' => 'TARS/note.md', 'frontmatter' => ['type' => 'a-traiter']]);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commit')->once());

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'TARS/note.md',
            'action' => 'create_task',
            'frontmatter' => ['title' => 'Rappeler Marc'],
            'confidence' => 'medium',
            'reason' => 'Peut-être.',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Scheduled, 'todo');

    expect(BrainSuggestion::firstOrFail()->status)->toBe(BrainSuggestionStatus::Pending);
});

test('auto-applies create_goal at high confidence', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    File::put($this->vaultPath.'/TARS/note.md', "---\ntype: a-traiter\n---\n\nNouvel objectif.");
    BrainDocument::factory()->create(['path' => 'TARS/note.md', 'frontmatter' => ['type' => 'a-traiter']]);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commit')->once());

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'TARS/note.md',
            'action' => 'create_goal',
            'frontmatter' => ['title' => 'Nouvel objectif'],
            'confidence' => 'high',
            'reason' => 'x',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Scheduled, 'todo');

    $suggestion = BrainSuggestion::firstOrFail();
    expect($suggestion->status)->toBe(BrainSuggestionStatus::AutoApplied)
        ->and($suggestion->created_type)->toBe(Goal::class)
        ->and(Goal::where('title', 'Nouvel objectif')->exists())->toBeTrue();
});

test('falls back to pending when create_goal at high confidence duplicates an existing goal', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    Goal::factory()->create(['title' => 'Nouvel objectif']);
    File::put($this->vaultPath.'/TARS/note.md', "---\ntype: a-traiter\n---\n\nNouvel objectif.");
    BrainDocument::factory()->create(['path' => 'TARS/note.md', 'frontmatter' => ['type' => 'a-traiter']]);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commit')->once());

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'TARS/note.md',
            'action' => 'create_goal',
            'frontmatter' => ['title' => 'Nouvel objectif'],
            'confidence' => 'high',
            'reason' => 'x',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Scheduled, 'todo');

    expect(BrainSuggestion::firstOrFail()->status)->toBe(BrainSuggestionStatus::Pending)
        ->and(Goal::where('title', 'Nouvel objectif')->count())->toBe(1);
});

test('auto-applies create_list_item into the matching list', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    $checklist = Checklist::factory()->create(['name' => 'Courses']);
    File::put($this->vaultPath.'/TARS/note.md', "---\ntype: a-traiter\n---\n\nAcheter du lait.");
    BrainDocument::factory()->create(['path' => 'TARS/note.md', 'frontmatter' => ['type' => 'a-traiter']]);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldReceive('commit')->once());

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'path' => 'TARS/note.md',
            'action' => 'create_list_item',
            'target' => 'Courses',
            'frontmatter' => ['content' => 'Lait'],
            'confidence' => 'high',
            'reason' => 'x',
        ]])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Scheduled, 'todo');

    $suggestion = BrainSuggestion::firstOrFail();
    expect($suggestion->status)->toBe(BrainSuggestionStatus::AutoApplied)
        ->and($suggestion->created_type)->toBe(ChecklistItem::class)
        ->and($checklist->items()->where('content', 'Lait')->exists())->toBeTrue();
});

test('marks a-traiter notes as traité and commits after processing the todo mission', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    File::put($this->vaultPath.'/TARS/note.md', "---\ntype: a-traiter\ndate: '2026-07-27'\n---\n\nContenu.");
    $document = BrainDocument::factory()->create(['path' => 'TARS/note.md', 'frontmatter' => ['type' => 'a-traiter', 'date' => '2026-07-27']]);

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('commit')->once()->with(Mockery::type('string'), 'TARS/note.md', Mockery::type('string'));
    });

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([['path' => 'TARS/note.md', 'action' => 'none']])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Scheduled, 'todo');

    expect($document->fresh()->frontmatter['type'])->toBe('traite')
        ->and(File::get($this->vaultPath.'/TARS/note.md'))->toContain('traite');
});

test('does not flip frontmatter or commit for the tidy mission', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    $document = BrainDocument::factory()->create(['path' => 'Notes/plombier.md']);

    $this->mock(GitRepository::class, fn ($mock) => $mock->shouldNotReceive('commit'));

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([['path' => 'Notes/plombier.md', 'action' => 'none']])]));
    });

    app(CuratorAgent::class)->process(AgentRunTrigger::Scheduled, 'tidy');

    expect(BrainSuggestion::count())->toBe(0);
});
