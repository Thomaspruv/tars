<?php

use App\Models\JournalEntry;
use App\Models\Questionnaire;
use App\Models\QuestionnaireRun;
use App\Models\User;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/bilan-screen-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('commit');
    });
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('guests are redirected to the login page', function () {
    $this->get(route('bilan.index'))->assertRedirect(route('login'));
});

test('it renders both tabs and defaults to journal', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('bilan.index'))
        ->assertSee('Bilan de vie')
        ->assertSee('Journal')
        ->assertSee('Bilans');
});

test('it shows the empty state when the journal has no entries', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::bilan')->assertSee('Ton journal est vide.');
});

test('it lists journal entries in the timeline', function () {
    $this->actingAs(User::factory()->create());

    JournalEntry::factory()->create(['date' => today(), 'summary' => 'Journée productive', 'mood' => 8]);

    Livewire::test('pages::bilan')
        ->assertSee('Journée productive')
        ->assertSee('8');
});

test('it creates a manual entry through JournalWriter', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::bilan')
        ->call('openEntryModal')
        ->set('entrySummary', 'Journée écrite depuis l\'app')
        ->set('entryMood', 7)
        ->call('saveEntry');

    $entry = JournalEntry::whereDate('date', today())->first();
    expect($entry)->not->toBeNull()
        ->and($entry->summary)->toBe('Journée écrite depuis l\'app')
        ->and($entry->mood)->toBe(7)
        ->and($entry->source)->toBe('manual');
});

test('reopening an existing day pre-fills mood but leaves the summary empty for an addition', function () {
    $this->actingAs(User::factory()->create());

    JournalEntry::factory()->create(['date' => today(), 'mood' => 6, 'summary' => 'Texte existant']);

    $component = Livewire::test('pages::bilan')->call('openEntryModal', today()->toDateString());

    expect($component->get('entryMood'))->toBe(6)
        ->and($component->get('entrySummary'))->toBe('');
});

test('the pending bilan banner and sidebar count show when a run is pending', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create(['name' => 'Retro perso']);
    QuestionnaireRun::factory()->create(['questionnaire_id' => $questionnaire->id]);

    Livewire::test('pages::bilan', ['activeTab' => 'bilans'])
        ->set('activeTab', 'bilans')
        ->assertSee('Retro perso')
        ->assertSee('en attente');
});

test('toggling a questionnaire flips its active state', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create(['is_active' => true]);

    Livewire::test('pages::bilan')->call('toggleQuestionnaire', $questionnaire->id);

    expect($questionnaire->fresh()->is_active)->toBeFalse();
});
