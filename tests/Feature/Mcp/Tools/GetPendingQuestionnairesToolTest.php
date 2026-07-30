<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetPendingQuestionnairesTool;
use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireRun;

test('says when there is nothing pending', function () {
    TarsServer::tool(GetPendingQuestionnairesTool::class)
        ->assertOk()
        ->assertSee('Aucun bilan en attente.');
});

test('lists pending runs with their remaining questions', function () {
    $questionnaire = Questionnaire::factory()->create([
        'name' => 'Retro perso',
        'questions' => [
            ['text' => 'Satisfaction, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
            ['text' => 'Un mot sur la période', 'type' => 'text'],
        ],
    ]);
    $run = QuestionnaireRun::factory()->create(['questionnaire_id' => $questionnaire->id, 'due_date' => '2026-08-01']);

    TarsServer::tool(GetPendingQuestionnairesTool::class)
        ->assertOk()
        ->assertSee('Retro perso')
        ->assertSee('Satisfaction, de 1 à 10')
        ->assertSee('Un mot sur la période');
});

test('excludes already-answered questions from the remaining list', function () {
    $questionnaire = Questionnaire::factory()->create([
        'name' => 'Retro perso',
        'questions' => [
            ['text' => 'Satisfaction, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
            ['text' => 'Un mot sur la période', 'type' => 'text'],
        ],
    ]);
    $run = QuestionnaireRun::factory()->create(['questionnaire_id' => $questionnaire->id]);
    QuestionnaireAnswer::factory()->create([
        'run_id' => $run->id,
        'question_text' => 'Satisfaction, de 1 à 10',
        'type' => 'scale',
        'answer_numeric' => 7,
    ]);

    TarsServer::tool(GetPendingQuestionnairesTool::class)
        ->assertOk()
        ->assertSee('Un mot sur la période')
        ->assertDontSee('Satisfaction, de 1 à 10');
});
