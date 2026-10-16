<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\BrainSuggestionStatus;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Models\Entity;
use App\Support\Agents\AgentRunner;
use App\Support\Brain\BrainHealth;
use App\Support\Curator\CuratorAgent;
use App\Support\Curator\CuratorContextBuilder;
use App\Support\Curator\SuggestionApplier;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/conformance-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

function nonConformantDocument(string $path = 'Notes/marc.md', array $frontmatter = ['type' => 'insight']): BrainDocument
{
    return BrainDocument::factory()->create([
        'path' => $path,
        'title' => 'Marc',
        'frontmatter' => $frontmatter,
        'content' => 'Marc Dupont, expert-comptable.',
        'is_conformant' => false,
    ]);
}

test('the context builder submits the non conformant documents with their diagnosis', function () {
    nonConformantDocument();
    BrainDocument::factory()->create(['path' => 'Notes/ok.md', 'title' => 'OK', 'is_conformant' => true, 'note_type' => 'note']);

    $documents = app(CuratorContextBuilder::class)->conformanceDocuments();

    expect($documents->pluck('path')->all())->toBe(['Notes/marc.md']);

    $context = app(CuratorContextBuilder::class)->forConformance($documents);

    expect($context)->toContain('Documents à mettre en conformité')
        ->toContain('Notes/marc.md')
        ->toContain('Non conforme')
        ->toContain('expert-comptable');
});

test('the context builder submits a conformant note sitting in the wrong folder', function () {
    BrainDocument::factory()->create([
        'path' => 'Notes/marc-dupont.md',
        'title' => 'Marc Dupont',
        'note_type' => 'person',
        'is_conformant' => true,
    ]);

    $documents = app(CuratorContextBuilder::class)->conformanceDocuments();

    expect($documents->pluck('path')->all())->toBe(['Notes/marc-dupont.md'])
        ->and(app(CuratorContextBuilder::class)->forConformance($documents))->toContain('Mal placé');
});

test('the context builder submits one of each probable duplicate pair, not both', function () {
    BrainDocument::factory()->create(['path' => 'Notes/fiscalite-sarl.md', 'title' => 'Fiscalité SARL', 'note_type' => 'note', 'is_conformant' => true]);
    BrainDocument::factory()->create(['path' => 'Notes/fiscalite-sarl-2.md', 'title' => 'Fiscalité SARL', 'note_type' => 'note', 'is_conformant' => true]);

    // Submitting both would let the curator answer « merge A → B » and
    // « merge B → A » in the same batch.
    $paths = app(CuratorContextBuilder::class)->conformanceDocuments()->pluck('path')->all();

    expect($paths)->toHaveCount(1)
        ->and($paths[0])->toStartWith('Notes/fiscalite-sarl');
});

test('a document already waiting in a batch is not submitted twice', function () {
    $document = nonConformantDocument();
    BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'batch_id' => 'lot-1',
        'status' => BrainSuggestionStatus::Pending,
    ]);

    expect(app(CuratorContextBuilder::class)->conformanceDocuments())->toHaveCount(0);
});

test('the context builder never submits Profil, Hermes territory or a generated file', function () {
    BrainDocument::factory()->create(['path' => 'Profil/perso.md', 'is_conformant' => false]);
    BrainDocument::factory()->create(['path' => '.hermes/rules.md', 'is_conformant' => false]);
    BrainDocument::factory()->create(['path' => 'README.md', 'is_conformant' => false]);
    BrainDocument::factory()->create(['path' => 'Personnes/_index.md', 'is_conformant' => false]);

    expect(app(CuratorContextBuilder::class)->conformanceDocuments())->toHaveCount(0);
});

test('a document in the sas or the archives is never called misplaced', function (string $path) {
    $document = BrainDocument::factory()->create(['path' => $path, 'note_type' => 'person', 'is_conformant' => true]);

    expect(BrainHealth::isMisplaced($document))->toBeFalse();
})->with(['TARS/2026-10-16-marc.md', 'Archives/marc.md']);

test('runs one LLM call per batch of forty documents, all logged', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);

    foreach (range(1, 45) as $index) {
        nonConformantDocument("Notes/note-{$index}.md");
    }

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->twice()->andReturn(
            AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => '[]']),
        );
    });

    $runs = app(CuratorAgent::class)->processConformance(AgentRunTrigger::Scheduled);

    expect($runs)->toHaveCount(2)
        ->and(CuratorContextBuilder::BATCH_SIZE)->toBe(40);
});

test('creates pending suggestions grouped by batch, never auto-applied', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    $document = nonConformantDocument();

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create([
            'status' => AgentRunStatus::Success,
            'output' => json_encode([[
                'path' => 'Notes/marc.md',
                'action' => 'complete',
                'frontmatter' => ['schema' => 3, 'type' => 'person', 'title' => 'Marc Dupont', 'date' => '2026-07-26'],
                'confidence' => 'high',
                'reason' => 'Frontmatter v2 : type inconnu, titre et date manquants.',
            ]]),
        ]));
    });

    app(CuratorAgent::class)->processConformance(AgentRunTrigger::Manual);

    $suggestion = BrainSuggestion::firstOrFail();

    expect($suggestion->status)->toBe(BrainSuggestionStatus::Pending)
        ->and($suggestion->batch_id)->not->toBeNull()
        ->and($suggestion->brain_document_id)->toBe($document->id);
});

test('rejects an action that does not belong to the conformance mission', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    nonConformantDocument();

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create([
            'status' => AgentRunStatus::Success,
            'output' => json_encode([[
                'path' => 'Notes/marc.md',
                'action' => 'create_task',
                'frontmatter' => ['title' => 'Rappeler Marc'],
                'confidence' => 'high',
                'reason' => 'Hors mission.',
            ]]),
        ]));
    });

    app(CuratorAgent::class)->processConformance(AgentRunTrigger::Manual);

    expect(BrainSuggestion::count())->toBe(0);
});

test('accepts a legacy folder as the source of a move — that is the migration', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    nonConformantDocument('00 - Meta/marc.md', ['type' => 'contact']);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create([
            'status' => AgentRunStatus::Success,
            'output' => json_encode([[
                'path' => '00 - Meta/marc.md',
                'action' => 'move',
                'target' => 'Personnes/marc-dupont.md',
                'frontmatter' => ['schema' => 3, 'type' => 'person', 'title' => 'Marc Dupont', 'date' => '2026-07-26'],
                'confidence' => 'medium',
                'reason' => 'Fiche de personne rangée dans un dossier hérité.',
            ]]),
        ]));
    });

    app(CuratorAgent::class)->processConformance(AgentRunTrigger::Manual);

    expect(BrainSuggestion::firstOrFail()->target)->toBe('Personnes/marc-dupont.md');
});

test('never touches Hermes own memory territory', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    BrainDocument::factory()->create(['path' => '.hermes/active-context.md', 'is_conformant' => false]);
    nonConformantDocument();

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create([
            'status' => AgentRunStatus::Success,
            'output' => json_encode([[
                'path' => '.hermes/active-context.md',
                'action' => 'archive',
                'confidence' => 'high',
                'reason' => 'Périmé.',
            ]]),
        ]));
    });

    app(CuratorAgent::class)->processConformance(AgentRunTrigger::Manual);

    expect(BrainSuggestion::count())->toBe(0);
});

test('rejects a destination that would leave the ontology, or the vault', function (string $target) {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    nonConformantDocument();

    $this->mock(AgentRunner::class, function ($mock) use ($target): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create([
            'status' => AgentRunStatus::Success,
            'output' => json_encode([[
                'path' => 'Notes/marc.md',
                'action' => 'move',
                'target' => $target,
                'confidence' => 'high',
                'reason' => 'Destination inventée.',
            ]]),
        ]));
    });

    app(CuratorAgent::class)->processConformance(AgentRunTrigger::Manual);

    expect(BrainSuggestion::count())->toBe(0);
})->with([
    'un dossier inventé' => ['Contacts/marc.md'],
    'une sortie du vault' => ['../../ailleurs/marc.md'],
    'un chemin absolu' => ['/etc/marc.md'],
]);

test('marks the run failed on invalid json without writing anything', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    nonConformantDocument();

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => 'pas du json']));
    });

    $runs = app(CuratorAgent::class)->processConformance(AgentRunTrigger::Manual);

    expect($runs->first()->fresh()->status)->toBe(AgentRunStatus::Failed)
        ->and(BrainSuggestion::count())->toBe(0);
});

test('does nothing at all when the curator is not configured', function () {
    nonConformantDocument();

    expect(app(CuratorAgent::class)->processConformance(AgentRunTrigger::Manual))->toHaveCount(0)
        ->and(BrainSuggestion::count())->toBe(0);
});

test('a limit caps how many documents one pass submits', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);

    foreach (range(1, 45) as $index) {
        nonConformantDocument("Notes/note-{$index}.md");
    }

    // 45 documents would be two batches; a limit of 10 is a single one.
    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => '[]']));
    });

    expect(app(CuratorAgent::class)->processConformance(AgentRunTrigger::Manual, limit: 10))->toHaveCount(1);
});

test('the prompt carries the vault README as its constitution', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);
    nonConformantDocument();

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(function ($config, string $systemPrompt, array $messages, $trigger): bool {
                return str_contains($systemPrompt, 'Le vault — la seconde mémoire de Thomas')
                    && str_contains($systemPrompt, 'mission **conformité**')
                    && ! str_contains($systemPrompt, '{{VAULT_README}}');
            })
            ->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => '[]']));
    });

    expect(app(CuratorAgent::class)->processConformance(AgentRunTrigger::Manual))->toHaveCount(1);
});

test('the health report counts conformance, misplacement, pending batches and the sas', function () {
    BrainDocument::factory()->create(['path' => 'Notes/ok.md', 'note_type' => 'note', 'is_conformant' => true]);
    BrainDocument::factory()->create(['path' => 'Notes/ko.md', 'is_conformant' => false]);
    BrainDocument::factory()->create(['path' => 'Notes/marc.md', 'note_type' => 'person', 'is_conformant' => true]);
    BrainDocument::factory()->create(['path' => 'TARS/2026-10-16-brut.md', 'note_type' => 'a-traiter', 'status' => 'active', 'is_conformant' => true]);
    BrainDocument::factory()->create(['path' => 'Profil/perso.md', 'is_conformant' => false]);

    $suggestionDocument = BrainDocument::where('path', 'Notes/ko.md')->firstOrFail();
    BrainSuggestion::factory()->create(['brain_document_id' => $suggestionDocument->id, 'batch_id' => 'lot-1', 'status' => BrainSuggestionStatus::Pending]);
    BrainSuggestion::factory()->create(['brain_document_id' => $suggestionDocument->id, 'batch_id' => null, 'status' => BrainSuggestionStatus::Pending]);

    $report = app(BrainHealth::class)->report();

    expect($report->documents)->toBe(5)
        ->and($report->underContract)->toBe(4)
        ->and($report->conformant)->toBe(3)
        ->and($report->conformanceRate)->toBe(75)
        ->and($report->misplaced)->toBe(1)
        ->and($report->pendingConformanceSuggestions)->toBe(1)
        ->and($report->sasNotes)->toBe(1)
        ->and($report->sentence())->toBe('Mémoire : 75 % conforme, 1 proposition(s) en attente, 1 note(s) dans le sas.');
});

test('the conformance command stays quiet when scheduled and unconfigured', function () {
    $this->artisan('curator:conformance --scheduled')->assertExitCode(0);
});

test('the conformance command reports that there is nothing to do', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);

    $this->artisan('curator:conformance')
        ->expectsOutputToContain('Rien à mettre en conformité')
        ->assertExitCode(0);
});

test('accepting a whole batch moves the files and completes the frontmatter in a single commit', function () {
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    File::put($this->vaultPath.'/Notes/marc.md', "---\ntype: insight\n---\n\nMarc Dupont, expert-comptable.");
    File::put($this->vaultPath.'/Notes/alpha.md', "---\ntype: insight\n---\n\nSARL Alpha, le client.");

    Entity::factory()->create(['name' => 'SARL Alpha']);

    $marc = BrainDocument::factory()->create(['path' => 'Notes/marc.md', 'title' => 'Marc', 'frontmatter' => ['type' => 'insight']]);
    $alpha = BrainDocument::factory()->create(['path' => 'Notes/alpha.md', 'title' => 'Alpha', 'frontmatter' => ['type' => 'insight']]);

    $run = AgentRun::factory()->create(['status' => AgentRunStatus::Success]);

    BrainSuggestion::factory()->create([
        'brain_document_id' => $marc->id,
        'agent_run_id' => $run->id,
        'batch_id' => 'lot-1',
        'action' => 'move',
        'target' => 'Personnes/marc-dupont.md',
        'frontmatter_patch' => ['type' => 'person', 'title' => 'Marc Dupont', 'date' => '2026-07-26'],
    ]);
    BrainSuggestion::factory()->create([
        'brain_document_id' => $alpha->id,
        'agent_run_id' => $run->id,
        'batch_id' => 'lot-1',
        'action' => 'complete',
        'frontmatter_patch' => ['type' => 'note', 'title' => 'SARL Alpha', 'date' => '2026-07-26', 'entity' => 'SARL Alpha'],
    ]);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once()->with(
        Mockery::type('string'),
        Mockery::type('array'),
        'tars: curator conformance batch lot-1',
    ));

    app(SuggestionApplier::class)->applyBatch(
        BrainSuggestion::where('batch_id', 'lot-1')->with('brainDocument')->get(),
        'curator conformance batch lot-1',
    );

    expect(File::exists($this->vaultPath.'/Personnes/marc-dupont.md'))->toBeTrue()
        ->and(File::exists($this->vaultPath.'/Notes/marc.md'))->toBeFalse()
        ->and(File::get($this->vaultPath.'/Personnes/marc-dupont.md'))->toContain('type: person')
        ->and(File::get($this->vaultPath.'/Notes/alpha.md'))->toContain('schema: 3')
        ->and(BrainSuggestion::where('status', BrainSuggestionStatus::Accepted)->count())->toBe(2)
        ->and(BrainDocument::where('path', 'Notes/alpha.md')->first()->entities)->toHaveCount(1);
});

test('a batch that both moves and rewrites the same note falls back to one commit each', function () {
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    File::put($this->vaultPath.'/Notes/marc.md', "---\ntype: insight\n---\n\nMarc Dupont.");

    $document = BrainDocument::factory()->create(['path' => 'Notes/marc.md', 'title' => 'Marc', 'frontmatter' => ['type' => 'insight']]);
    $run = AgentRun::factory()->create(['status' => AgentRunStatus::Success]);

    // The curator should never emit both, but if it does the note must not end
    // up existing at two paths at once.
    BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'agent_run_id' => $run->id,
        'batch_id' => 'lot-1',
        'action' => 'move',
        'target' => 'Personnes/marc-dupont.md',
        'frontmatter_patch' => ['type' => 'person', 'title' => 'Marc Dupont', 'date' => '2026-07-26'],
    ]);
    BrainSuggestion::factory()->create([
        'brain_document_id' => $document->id,
        'agent_run_id' => $run->id,
        'batch_id' => 'lot-1',
        'action' => 'complete',
        'frontmatter_patch' => ['type' => 'note', 'title' => 'Marc', 'date' => '2026-07-26'],
    ]);

    // One commit per suggestion instead of one for the batch.
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->with(
        Mockery::type('string'),
        Mockery::type('array'),
        Mockery::pattern('/^tars: curator (move|complete) /'),
    )->atLeast()->once());

    try {
        app(SuggestionApplier::class)->applyBatch(
            BrainSuggestion::where('batch_id', 'lot-1')->with('brainDocument')->get(),
            'curator conformance batch lot-1',
        );
    } catch (RuntimeException) {
        // The second suggestion has nothing left to read: that is the point.
    }

    expect(File::exists($this->vaultPath.'/Notes/marc.md'))->toBeFalse()
        ->and(File::exists($this->vaultPath.'/Personnes/marc-dupont.md'))->toBeTrue();
});
