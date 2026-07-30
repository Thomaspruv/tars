<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\LogJournalTool;
use App\Models\JournalEntry;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/mcp-journal-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('commit');
    });
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('logs an entry with a mood', function () {
    TarsServer::tool(LogJournalTool::class, [
        'summary' => 'Bonne journée, projet bien avancé.',
        'mood' => 8,
    ])->assertOk()->assertSee('Mood 8/10');

    $entry = JournalEntry::first();
    expect($entry->mood)->toBe(8)
        ->and($entry->source)->toBe('hermes');
});

test('logs an entry without a mood', function () {
    TarsServer::tool(LogJournalTool::class, ['summary' => 'Journée normale.'])
        ->assertOk()
        ->assertSee('Noté.')
        ->assertDontSee('Mood');

    expect(JournalEntry::first()->mood)->toBeNull();
});

test('accepts "hier" as a date', function () {
    TarsServer::tool(LogJournalTool::class, ['summary' => 'Hier c\'était calme.', 'date' => 'hier'])
        ->assertOk();

    expect(JournalEntry::first()->date->toDateString())->toBe(today()->subDay()->toDateString());
});

test('refuses a future date', function () {
    $futureDate = today()->addDays(3)->toDateString();

    TarsServer::tool(LogJournalTool::class, ['summary' => 'Test.', 'date' => $futureDate])
        ->assertHasErrors(['future']);

    expect(JournalEntry::count())->toBe(0);
});

test('rejects a mood out of range', function () {
    TarsServer::tool(LogJournalTool::class, ['summary' => 'Test.', 'mood' => 11])
        ->assertHasErrors();

    expect(JournalEntry::count())->toBe(0);
});

test('a second call the same day merges into the existing entry', function () {
    TarsServer::tool(LogJournalTool::class, ['summary' => 'Première partie.', 'mood' => 6])->assertOk();
    TarsServer::tool(LogJournalTool::class, ['summary' => 'Deuxième partie.'])->assertOk();

    expect(JournalEntry::count())->toBe(1);

    $entry = JournalEntry::first();
    expect($entry->summary)->toContain('Première partie.')
        ->and($entry->summary)->toContain('Deuxième partie.')
        ->and($entry->mood)->toBe(6);
});
