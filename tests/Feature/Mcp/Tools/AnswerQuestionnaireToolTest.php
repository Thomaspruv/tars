<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\AnswerQuestionnaireTool;
use App\Models\Questionnaire;
use App\Models\QuestionnaireRun;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/answer-questionnaire-test-'.uniqid();
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

test('accepts a partial answer without completing the run', function () {
    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'retro perso',
        'answers' => [
            ['question' => 'Satisfaction, de 1 à 10', 'answer' => '8'],
        ],
    ])->assertOk()->assertSee('1 réponse(s) enregistrée(s).');

    expect($this->run->fresh()->status->value)->toBe('pending')
        ->and($this->run->answers()->count())->toBe(1);
});

test('completes the run once every question has an answer', function () {
    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'retro perso',
        'answers' => [
            ['question' => 'Satisfaction, de 1 à 10', 'answer' => '8'],
        ],
    ])->assertOk();

    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'retro perso',
        'answers' => [
            ['question' => 'Un mot sur la période', 'answer' => 'Dense mais bien.'],
        ],
    ])->assertOk()->assertSee('Bilan complété.');

    expect($this->run->fresh()->status->value)->toBe('completed')
        ->and($this->run->fresh()->note_path)->not->toBeNull();
});

test('rejects a scale answer out of bounds without writing it', function () {
    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'retro perso',
        'answers' => [
            ['question' => 'Satisfaction, de 1 à 10', 'answer' => '15'],
        ],
    ])->assertOk()->assertSee('Non prise(s) en compte');

    expect($this->run->answers()->count())->toBe(0);
});

test('a valid answer in the same call is still accepted when another is rejected', function () {
    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'retro perso',
        'answers' => [
            ['question' => 'Satisfaction, de 1 à 10', 'answer' => '15'],
            ['question' => 'Un mot sur la période', 'answer' => 'Bonne période.'],
        ],
    ])->assertOk()->assertSee('1 réponse(s) enregistrée(s).');

    expect($this->run->answers()->count())->toBe(1);
});

test('fuzzy matches the questionnaire name', function () {
    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'retro prso',
        'answers' => [
            ['question' => 'Satisfaction, de 1 à 10', 'answer' => '9'],
        ],
    ])->assertOk();

    expect($this->run->answers()->count())->toBe(1);
});

test('errors when no questionnaire matches', function () {
    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'inexistant',
        'answers' => [
            ['question' => 'Satisfaction, de 1 à 10', 'answer' => '9'],
        ],
    ])->assertHasErrors(['Aucun questionnaire']);
});

test('errors when there is no pending run', function () {
    $this->run->update(['status' => 'completed']);

    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'retro perso',
        'answers' => [
            ['question' => 'Satisfaction, de 1 à 10', 'answer' => '9'],
        ],
    ])->assertHasErrors(['Aucun bilan en attente']);
});

test('a second call answering an already-answered question updates it instead of duplicating', function () {
    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'retro perso',
        'answers' => [
            ['question' => 'Satisfaction, de 1 à 10', 'answer' => '6'],
        ],
    ])->assertOk();

    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'retro perso',
        'answers' => [
            ['question' => 'Satisfaction, de 1 à 10', 'answer' => '9'],
        ],
    ])->assertOk();

    expect($this->run->answers()->count())->toBe(1)
        ->and($this->run->answers()->first()->answer_numeric)->toBe(9.0);
});

test('a number answer beyond the decimal(8,2) column range is rejected', function () {
    $questionnaire = Questionnaire::factory()->create([
        'name' => 'Registre financier',
        'questions' => [['text' => 'Montant du contrat', 'type' => 'number']],
    ]);
    $run = QuestionnaireRun::factory()->create(['questionnaire_id' => $questionnaire->id]);

    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'registre financier',
        'answers' => [
            ['question' => 'Montant du contrat', 'answer' => '1000000'],
        ],
    ])->assertOk()->assertSee('Non prise(s) en compte');

    expect($run->answers()->count())->toBe(0);
});

test('a questionnaire with no questions never completes on its own', function () {
    $questionnaire = Questionnaire::factory()->create(['name' => 'Nouveau questionnaire', 'questions' => []]);
    $run = QuestionnaireRun::factory()->create(['questionnaire_id' => $questionnaire->id]);

    TarsServer::tool(AnswerQuestionnaireTool::class, [
        'questionnaire' => 'nouveau questionnaire',
        'answers' => [
            ['question' => 'Une question qui ne correspond a rien', 'answer' => 'peu importe'],
        ],
    ])->assertOk()->assertDontSee('Bilan complété.');

    expect($run->fresh()->status->value)->toBe('pending');
});
