<?php

use App\Models\Questionnaire;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireRun;

test('a questionnaire has many runs', function () {
    $questionnaire = Questionnaire::factory()->create();
    QuestionnaireRun::factory()->count(2)->create(['questionnaire_id' => $questionnaire->id]);

    expect($questionnaire->runs)->toHaveCount(2);
});

test('a run belongs to a questionnaire and has many answers', function () {
    $questionnaire = Questionnaire::factory()->create();
    $run = QuestionnaireRun::factory()->create(['questionnaire_id' => $questionnaire->id]);
    QuestionnaireAnswer::factory()->create(['run_id' => $run->id]);

    expect($run->questionnaire->id)->toBe($questionnaire->id)
        ->and($run->answers)->toHaveCount(1);
});

test('deleting a questionnaire cascades to its runs, and deleting a run cascades to its answers', function () {
    $questionnaire = Questionnaire::factory()->create();
    $run = QuestionnaireRun::factory()->create(['questionnaire_id' => $questionnaire->id]);
    $answer = QuestionnaireAnswer::factory()->create(['run_id' => $run->id]);

    $questionnaire->delete();

    expect(QuestionnaireRun::find($run->id))->toBeNull()
        ->and(QuestionnaireAnswer::find($answer->id))->toBeNull();
});

test('the seeded default questionnaires exist', function () {
    expect(Questionnaire::where('name', 'Bilan mensuel')->exists())->toBeTrue()
        ->and(Questionnaire::where('name', 'Bilan annuel')->exists())->toBeTrue();

    $monthly = Questionnaire::where('name', 'Bilan mensuel')->first();
    expect($monthly->frequency->value)->toBe('monthly')
        ->and($monthly->anchor)->toBe('1')
        ->and($monthly->questions)->toHaveCount(4)
        ->and($monthly->is_active)->toBeTrue();

    $yearly = Questionnaire::where('name', 'Bilan annuel')->first();
    expect($yearly->frequency->value)->toBe('yearly')
        ->and($yearly->anchor)->toBe('12-31')
        ->and($yearly->questions)->toHaveCount(8);
});
