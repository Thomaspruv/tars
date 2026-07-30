<?php

use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireRun;
use App\Models\User;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/run-fill-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('commit');
    });

    $this->questionnaire = Questionnaire::factory()->create([
        'name' => 'Retro perso',
        'questions' => [
            ['text' => 'Satisfaction, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
            ['text' => 'Un mot sur la période', 'type' => 'text'],
        ],
    ]);
    $this->run = QuestionnaireRun::factory()->create(['questionnaire_id' => $this->questionnaire->id]);
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('guests are redirected to the login page', function () {
    $this->get(route('bilan.run.fill', $this->run))->assertRedirect(route('login'));
});

test('answers already given via Hermes are pre-filled and badged', function () {
    $this->actingAs(User::factory()->create());

    QuestionnaireAnswer::factory()->create([
        'run_id' => $this->run->id,
        'question_text' => 'Satisfaction, de 1 à 10',
        'type' => 'scale',
        'answer_numeric' => 8,
    ]);

    Livewire::test('pages::bilan.run', ['run' => $this->run])
        ->assertSee('répondu via Hermes')
        ->assertSet('answers.0', '8');
});

test('saving progress persists answers without completing the run', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::bilan.run', ['run' => $this->run])
        ->set('answers.0', '7')
        ->call('saveProgress');

    expect($this->run->fresh()->status->value)->toBe('pending')
        ->and($this->run->answers()->count())->toBe(1);
});

test('completing once every question is answered writes the vault note', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::bilan.run', ['run' => $this->run])
        ->set('answers.0', '7')
        ->set('answers.1', 'Bonne période')
        ->call('complete');

    $this->run->refresh();
    expect($this->run->status->value)->toBe('completed')
        ->and($this->run->note_path)->not->toBeNull();

    expect(File::exists("{$this->vaultPath}/{$this->run->note_path}"))->toBeTrue();
});

test('completing does nothing when a question is still unanswered', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::bilan.run', ['run' => $this->run])
        ->set('answers.0', '7')
        ->call('complete');

    expect($this->run->fresh()->status->value)->toBe('pending');
});

test('an out-of-bounds scale answer is rejected, matching the MCP tool', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::bilan.run', ['run' => $this->run])
        ->set('answers.0', '999')
        ->set('answers.1', 'Bonne période');

    expect($component->instance()->allAnswered())->toBeFalse();

    $component->call('complete');

    expect($this->run->fresh()->status->value)->toBe('pending')
        ->and($this->run->answers()->where('question_text', 'Satisfaction, de 1 à 10')->exists())->toBeFalse();
});

test('a questionnaire with no questions never completes on its own', function () {
    $this->actingAs(User::factory()->create());

    $emptyQuestionnaire = Questionnaire::factory()->create(['name' => 'Vide', 'questions' => []]);
    $emptyRun = QuestionnaireRun::factory()->create(['questionnaire_id' => $emptyQuestionnaire->id]);

    Livewire::test('pages::bilan.run', ['run' => $emptyRun])->call('complete');

    expect($emptyRun->fresh()->status->value)->toBe('pending');
});
