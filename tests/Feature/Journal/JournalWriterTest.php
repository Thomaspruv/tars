<?php

use App\Models\JournalEntry;
use App\Support\Brain\GitRepository;
use App\Support\Journal\JournalWriter;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/journal-writer-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('commit');
    });
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('writes a new entry to the vault and the database', function () {
    $entry = app(JournalWriter::class)->write(
        summary: 'Belle journée, bien avancé sur le projet.',
        mood: 8,
        moodLabel: 'content',
        highlights: ['Rappel du plombier réglé'],
        date: null,
        source: 'hermes',
    );

    expect($entry->mood)->toBe(8)
        ->and($entry->mood_label)->toBe('content')
        ->and($entry->summary)->toBe('Belle journée, bien avancé sur le projet.')
        ->and($entry->highlights)->toBe(['Rappel du plombier réglé'])
        ->and($entry->source)->toBe('hermes')
        ->and($entry->note_path)->toBe("TARS/Journal/{$entry->date->format('Y')}/{$entry->date->format('Y-m-d')}.md");

    $absolutePath = "{$this->vaultPath}/{$entry->note_path}";
    expect(File::exists($absolutePath))->toBeTrue();

    $content = File::get($absolutePath);
    expect($content)->toContain('mood: 8')
        ->and($content)->toContain('## Résumé')
        ->and($content)->toContain('Belle journée')
        ->and($content)->toContain('## Moments')
        ->and($content)->toContain('- Rappel du plombier réglé');
});

test('a second write the same day merges instead of overwriting', function () {
    $writer = app(JournalWriter::class);

    $first = $writer->write(
        summary: 'Journée calme.',
        mood: 6,
        moodLabel: 'calme',
        highlights: [],
        date: null,
        source: 'hermes',
    );

    $second = $writer->write(
        summary: 'Ah et j\'ai oublié : super appel avec Marc.',
        mood: null,
        moodLabel: null,
        highlights: ['Appel avec Marc'],
        date: null,
        source: 'hermes',
    );

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::count())->toBe(1)
        ->and($second->mood)->toBe(6)
        ->and($second->mood_label)->toBe('calme')
        ->and($second->summary)->toContain('Journée calme.')
        ->and($second->summary)->toContain('super appel avec Marc')
        ->and($second->highlights)->toBe(['Appel avec Marc']);

    $content = File::get("{$this->vaultPath}/{$second->note_path}");
    expect($content)->toContain('## Résumé')
        ->and($content)->toContain('Journée calme.')
        ->and($content)->toContain('## Ajout — ')
        ->and($content)->toContain('super appel avec Marc');
});

test('a new mood provided on merge overwrites the previous one', function () {
    $writer = app(JournalWriter::class);

    $writer->write('Journée moyenne.', 5, null, [], null, 'hermes');
    $second = $writer->write('Finalement une super soirée.', 9, 'ravi', [], null, 'hermes');

    expect($second->mood)->toBe(9)
        ->and($second->mood_label)->toBe('ravi');

    $content = File::get("{$this->vaultPath}/{$second->note_path}");
    expect($content)->toContain('mood: 9')
        ->and($content)->toContain('mood_label: ravi');
});

test('a write before the pivot hour is attached to the previous day', function () {
    $expectedDate = today()->toDateString();
    $this->travelTo(today()->addDay()->setTime(2, 0));

    $entry = app(JournalWriter::class)->write('Vocal tardif.', null, null, [], null, 'hermes');

    expect($entry->date->toDateString())->toBe($expectedDate);
});

test('a write after the pivot hour stays on the current day', function () {
    $this->travelTo(today()->setTime(23, 0));

    $entry = app(JournalWriter::class)->write('Vocal du soir.', null, null, [], null, 'hermes');

    expect($entry->date->toDateString())->toBe(today()->toDateString());
});

test('an explicit date is respected regardless of the pivot hour', function () {
    $yesterday = today()->subDay();

    $entry = app(JournalWriter::class)->write('Hier.', null, null, [], $yesterday, 'hermes');

    expect($entry->date->toDateString())->toBe($yesterday->toDateString());
});
