<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetJournalTool;
use App\Models\JournalEntry;

test('returns the entry for a given date', function () {
    JournalEntry::factory()->create([
        'date' => today(),
        'mood' => 7,
        'mood_label' => 'content',
        'summary' => 'Bonne journée productive.',
    ]);

    TarsServer::tool(GetJournalTool::class, ['date' => "aujourd'hui"])
        ->assertOk()
        ->assertSee('mood 7/10')
        ->assertSee('content')
        ->assertSee('Bonne journée productive.');
});

test('says when there is no entry for the given date', function () {
    TarsServer::tool(GetJournalTool::class, ['date' => 'hier'])
        ->assertOk()
        ->assertSee('Aucune entrée de journal');
});

test('returns entries within a named month period', function () {
    JournalEntry::factory()->create(['date' => Carbon\Carbon::create(now()->year, 7, 10), 'summary' => 'Entrée de juillet']);
    JournalEntry::factory()->create(['date' => Carbon\Carbon::create(now()->year, 8, 10), 'summary' => 'Entrée d\'août']);

    TarsServer::tool(GetJournalTool::class, ['period' => 'juillet'])
        ->assertOk()
        ->assertSee('Entrée de juillet')
        ->assertDontSee('Entrée d\'août');
});

test('returns entries within the current week when asked "cette semaine"', function () {
    JournalEntry::factory()->create(['date' => today(), 'summary' => 'Entrée du jour']);
    JournalEntry::factory()->create(['date' => today()->subWeeks(3), 'summary' => 'Entrée ancienne']);

    TarsServer::tool(GetJournalTool::class, ['period' => 'cette semaine'])
        ->assertOk()
        ->assertSee('Entrée du jour')
        ->assertDontSee('Entrée ancienne');
});

test('defaults to the last 7 days when nothing is specified', function () {
    JournalEntry::factory()->create(['date' => today(), 'summary' => 'Récente']);
    JournalEntry::factory()->create(['date' => today()->subDays(20), 'summary' => 'Trop vieille']);

    TarsServer::tool(GetJournalTool::class)
        ->assertOk()
        ->assertSee('Récente')
        ->assertDontSee('Trop vieille');
});

test('says when there are no entries for the period', function () {
    TarsServer::tool(GetJournalTool::class, ['period' => 'juillet'])
        ->assertOk()
        ->assertSee('Aucune entrée de journal sur cette période.');
});
