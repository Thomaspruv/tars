<?php

use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireRun;
use App\Support\Brain\GitRepository;
use App\Support\Questionnaire\QuestionnaireCompleter;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/questionnaire-completer-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('commit');
    });
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('writes the vault note, commits, reindexes and marks the run completed', function () {
    $questionnaire = Questionnaire::factory()->create(['name' => 'Retro perso']);
    $run = QuestionnaireRun::factory()->create([
        'questionnaire_id' => $questionnaire->id,
        'due_date' => '2026-07-01',
    ]);
    QuestionnaireAnswer::factory()->create([
        'run_id' => $run->id,
        'question_text' => 'Satisfaction, de 1 à 10',
        'type' => 'scale',
        'answer_numeric' => 8,
        'answer_text' => null,
    ]);
    QuestionnaireAnswer::factory()->create([
        'run_id' => $run->id,
        'question_text' => 'Un mot sur la période',
        'type' => 'text',
        'answer_numeric' => null,
        'answer_text' => 'Dense mais bien.',
    ]);

    app(QuestionnaireCompleter::class)->complete($run);

    $run->refresh();
    expect($run->status->value)->toBe('completed')
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->note_path)->toBe('TARS/Journal/Bilans/2026-07-01 — Retro perso.md');

    $absolutePath = "{$this->vaultPath}/{$run->note_path}";
    expect(File::exists($absolutePath))->toBeTrue();

    $content = File::get($absolutePath);
    expect($content)->toContain('questionnaire: \'Retro perso\'')
        ->and($content)->toContain('## Satisfaction, de 1 à 10')
        ->and($content)->toContain('8')
        ->and($content)->toContain('## Un mot sur la période')
        ->and($content)->toContain('Dense mais bien.');
});

test('two weekly runs completed in the same month produce two distinct vault notes', function () {
    $questionnaire = Questionnaire::factory()->create(['name' => 'Point hebdo']);
    $firstRun = QuestionnaireRun::factory()->create(['questionnaire_id' => $questionnaire->id, 'due_date' => '2026-07-06']);
    $secondRun = QuestionnaireRun::factory()->create(['questionnaire_id' => $questionnaire->id, 'due_date' => '2026-07-13']);

    app(QuestionnaireCompleter::class)->complete($firstRun);
    app(QuestionnaireCompleter::class)->complete($secondRun);

    $firstRun->refresh();
    $secondRun->refresh();

    expect($firstRun->note_path)->not->toBe($secondRun->note_path)
        ->and(File::exists("{$this->vaultPath}/{$firstRun->note_path}"))->toBeTrue()
        ->and(File::exists("{$this->vaultPath}/{$secondRun->note_path}"))->toBeTrue();
});

test('completing an already-completed run is a no-op and does not commit again', function () {
    $questionnaire = Questionnaire::factory()->create(['name' => 'Retro perso']);
    $run = QuestionnaireRun::factory()->completed()->create(['questionnaire_id' => $questionnaire->id, 'due_date' => '2026-07-01']);
    QuestionnaireAnswer::factory()->create(['run_id' => $run->id, 'question_text' => 'Satisfaction', 'type' => 'scale', 'answer_numeric' => 8]);
    $completedAt = $run->completed_at;

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldNotReceive('commit');
    });

    app(QuestionnaireCompleter::class)->complete($run);

    expect($run->fresh()->completed_at->toDateTimeString())->toBe($completedAt->toDateTimeString());
});
