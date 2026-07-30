<?php

use App\Models\Questionnaire;
use App\Models\QuestionnaireRun;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $questionnaire = Questionnaire::factory()->create();

    $this->get(route('bilan.questionnaire.edit', $questionnaire))->assertRedirect(route('login'));
});

test('it saves name, frequency, anchor and questions', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create();

    Livewire::test('pages::bilan.questionnaire', ['questionnaire' => $questionnaire])
        ->set('name', 'Point trimestriel SARL')
        ->set('frequency', 'quarterly')
        ->set('anchor', '1')
        ->call('save');

    $questionnaire->refresh();
    expect($questionnaire->name)->toBe('Point trimestriel SARL')
        ->and($questionnaire->frequency->value)->toBe('quarterly')
        ->and($questionnaire->anchor)->toBe('1');
});

test('it adds and removes a question', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create(['questions' => []]);

    $component = Livewire::test('pages::bilan.questionnaire', ['questionnaire' => $questionnaire])
        ->call('addQuestion')
        ->set('questions.0.text', 'Une nouvelle question')
        ->set('questions.0.type', 'text')
        ->call('save');

    expect($questionnaire->fresh()->questions)->toHaveCount(1);

    $component->call('removeQuestion', 0)->call('save');

    expect($questionnaire->fresh()->questions)->toHaveCount(0);
});

test('it reorders questions', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create([
        'questions' => [
            ['text' => 'Premiere', 'type' => 'text'],
            ['text' => 'Seconde', 'type' => 'text'],
        ],
    ]);

    Livewire::test('pages::bilan.questionnaire', ['questionnaire' => $questionnaire])
        ->call('moveDown', 0)
        ->call('save');

    $questions = $questionnaire->fresh()->questions;
    expect($questions[0]['text'])->toBe('Seconde')
        ->and($questions[1]['text'])->toBe('Premiere');
});

test('editing questions never alters answers already recorded on past runs', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create([
        'questions' => [['text' => 'Satisfaction', 'type' => 'scale', 'scale_max' => 10]],
    ]);
    $run = QuestionnaireRun::factory()->completed()->create(['questionnaire_id' => $questionnaire->id]);
    $answer = $run->answers()->create(['question_text' => 'Satisfaction', 'type' => 'scale', 'answer_numeric' => 8]);

    Livewire::test('pages::bilan.questionnaire', ['questionnaire' => $questionnaire])
        ->set('questions.0.text', 'Satisfaction (renommée)')
        ->call('save');

    expect($answer->fresh()->question_text)->toBe('Satisfaction');
});

test('a questionnaire with runs cannot be deleted', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create();
    QuestionnaireRun::factory()->create(['questionnaire_id' => $questionnaire->id]);

    Livewire::test('pages::bilan.questionnaire', ['questionnaire' => $questionnaire])->call('delete');

    expect(Questionnaire::find($questionnaire->id))->not->toBeNull();
});

test('a questionnaire without runs can be deleted', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create();

    Livewire::test('pages::bilan.questionnaire', ['questionnaire' => $questionnaire])->call('delete');

    expect(Questionnaire::find($questionnaire->id))->toBeNull();
});

test('rejects a weekly anchor that is not a 3-letter weekday abbreviation', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create();

    Livewire::test('pages::bilan.questionnaire', ['questionnaire' => $questionnaire])
        ->set('frequency', 'weekly')
        ->set('anchor', 'monday')
        ->call('save')
        ->assertHasErrors(['anchor']);

    expect($questionnaire->fresh()->frequency->value)->not->toBe('weekly');
});

test('accepts a valid weekly anchor', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create();

    Livewire::test('pages::bilan.questionnaire', ['questionnaire' => $questionnaire])
        ->set('frequency', 'weekly')
        ->set('anchor', 'mon')
        ->call('save')
        ->assertHasNoErrors();

    expect($questionnaire->fresh()->anchor)->toBe('mon');
});

test('rejects a yearly anchor that is not in MM-DD format', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create();

    Livewire::test('pages::bilan.questionnaire', ['questionnaire' => $questionnaire])
        ->set('frequency', 'yearly')
        ->set('anchor', '1') // leftover monthly-style anchor
        ->call('save')
        ->assertHasErrors(['anchor']);
});

test('rejects a monthly anchor outside 1-31', function () {
    $this->actingAs(User::factory()->create());

    $questionnaire = Questionnaire::factory()->create();

    Livewire::test('pages::bilan.questionnaire', ['questionnaire' => $questionnaire])
        ->set('frequency', 'monthly')
        ->set('anchor', '32')
        ->call('save')
        ->assertHasErrors(['anchor']);
});
