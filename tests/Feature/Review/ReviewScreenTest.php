<?php

use App\Enums\DecisionSource;
use App\Models\Decision;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('shows an empty state when there is no review yet', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::review')
        ->assertSee('Aucune revue', false)
        ->assertSee("L'agent qui les écrit s'active sur l'écran Agents.", false);
});

test('shows the latest review by default', function () {
    $this->actingAs(User::factory()->create());

    Review::factory()->create(['period_start' => now()->subDays(14), 'period_end' => now()->subDays(7), 'generated_content' => '# Ancienne revue']);
    $latest = Review::factory()->create(['period_start' => now()->subDays(7), 'period_end' => now(), 'generated_content' => '# Revue récente']);

    $component = Livewire::test('pages::review');

    expect($component->get('currentReview')->id)->toBe($latest->id);

    // La revue se lit en trois étapes : les chiffres, puis ce qu'Hermès en
    // dit, puis ce que j'en retiens. Le texte de l'agent est donc à l'étape 2.
    $component->assertSee('Semaine '.$latest->period_end->isoWeek())
        ->call('goToStep', 2)
        ->assertSee('Revue récente');
});

test('answers a decision and creates a decision row', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['title' => 'Courir un semi-marathon']);

    $review = Review::factory()->create([
        'proposed_decisions' => [
            ['question' => 'On met en pause ?', 'goal' => 'Courir un semi-marathon', 'entity' => null, 'response' => null, 'answered_at' => null],
        ],
    ]);

    Livewire::test('pages::review')
        ->call('selectReview', $review->id)
        ->call('answerDecision', 0, 'oui');

    $decision = Decision::firstOrFail();
    expect($decision->content)->toBe('On met en pause ?')
        ->and($decision->context)->toBe('Réponse : Oui')
        ->and($decision->source)->toBe(DecisionSource::Review)
        ->and($decision->review_id)->toBe($review->id)
        ->and($decision->goal_id)->toBe($goal->id);

    expect($review->fresh()->proposed_decisions[0]['response'])->toBe('oui');
});

test('creates a "reported" decision when answering plus tard', function () {
    $this->actingAs(User::factory()->create());

    $review = Review::factory()->create([
        'proposed_decisions' => [
            ['question' => 'On relance le projet ?', 'goal' => null, 'entity' => null, 'response' => null, 'answered_at' => null],
        ],
    ]);

    Livewire::test('pages::review')
        ->call('selectReview', $review->id)
        ->call('answerDecision', 0, 'plus_tard');

    $decision = Decision::firstOrFail();
    expect($decision->content)->toBe('On relance le projet ?')
        ->and($decision->context)->toBe('Réponse : Reporté');
});

test('does not answer an already answered decision twice', function () {
    $this->actingAs(User::factory()->create());

    $review = Review::factory()->create([
        'proposed_decisions' => [
            ['question' => 'Déjà répondue ?', 'goal' => null, 'entity' => null, 'response' => 'oui', 'answered_at' => now()->toIso8601String()],
        ],
    ]);

    Livewire::test('pages::review')
        ->call('selectReview', $review->id)
        ->call('answerDecision', 0, 'non');

    expect(Decision::count())->toBe(0);
});

test('resolves an entity by name when answering a decision', function () {
    $this->actingAs(User::factory()->create());

    $entity = Entity::factory()->create(['name' => 'SARL Dupont']);

    $review = Review::factory()->create([
        'proposed_decisions' => [
            ['question' => 'On relance la SARL ?', 'goal' => null, 'entity' => 'SARL Dupont', 'response' => null, 'answered_at' => null],
        ],
    ]);

    Livewire::test('pages::review')
        ->call('selectReview', $review->id)
        ->call('answerDecision', 0, 'non');

    expect(Decision::firstOrFail()->entity_id)->toBe($entity->id);
});

test('resolves a goal by a fuzzy match when it was renamed after the review was generated', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['title' => 'Courir un semi-marathon en octobre']);

    $review = Review::factory()->create([
        'proposed_decisions' => [
            ['question' => 'On garde le rythme ?', 'goal' => 'Courir un semi-marathon', 'entity' => null, 'response' => null, 'answered_at' => null],
        ],
    ]);

    Livewire::test('pages::review')
        ->call('selectReview', $review->id)
        ->call('answerDecision', 0, 'oui');

    expect(Decision::firstOrFail()->goal_id)->toBe($goal->id);
});

test('saves user notes on blur', function () {
    $this->actingAs(User::factory()->create());

    $review = Review::factory()->create();

    Livewire::test('pages::review')
        ->call('selectReview', $review->id)
        ->set('userNotes', 'Ma remarque personnelle.');

    expect($review->fresh()->user_notes)->toBe('Ma remarque personnelle.');
});

test('marks a review as done', function () {
    $this->actingAs(User::factory()->create());

    $review = Review::factory()->create();

    Livewire::test('pages::review')
        ->call('selectReview', $review->id)
        ->call('markDone');

    expect($review->fresh()->completed_at)->not->toBeNull();
});

test('switches to another review from the top bar', function () {
    $this->actingAs(User::factory()->create());

    $older = Review::factory()->create(['period_start' => now()->subDays(21), 'period_end' => now()->subDays(14), 'generated_content' => '# Vieille revue']);
    Review::factory()->create(['period_start' => now()->subDays(7), 'period_end' => now(), 'generated_content' => '# Revue récente']);

    Livewire::test('pages::review')
        ->call('selectReview', $older->id)
        ->call('goToStep', 2)
        ->assertSee('Vieille revue');
});

/**
 * Écran 11 du doc : trois étapes, les chiffres d'abord. Ils sont lus dans mes
 * données, pas dans le texte de l'agent.
 */
test('the first step counts the period in real numbers', function () {
    $this->actingAs(User::factory()->create());

    $review = Review::factory()->create([
        'period_start' => now()->subDays(7)->startOfDay(),
        'period_end' => now()->startOfDay(),
    ]);

    $goal = Goal::factory()->create(['title' => 'Lancer l\'abonnement']);
    Task::factory()->count(3)->for($goal)->create([
        'status' => 'done',
        'completed_at' => now()->subDays(2),
    ]);

    Livewire::test('pages::review')
        ->call('selectReview', $review->id)
        ->assertSee('tâches cochées')
        ->assertSee('Où la semaine est passée')
        ->assertSee('Lancer l\'abonnement');
});

test('changing review starts again at the first step', function () {
    $this->actingAs(User::factory()->create());

    $first = Review::factory()->create(['period_end' => now()->subWeek()]);
    $second = Review::factory()->create(['period_end' => now()]);

    Livewire::test('pages::review')
        ->call('selectReview', $first->id)
        ->call('goToStep', 3)
        ->assertSet('step', 3)
        ->call('selectReview', $second->id)
        ->assertSet('step', 1);
});

test('the step never leaves the three that exist', function () {
    $this->actingAs(User::factory()->create());
    Review::factory()->create();

    Livewire::test('pages::review')
        ->call('goToStep', 9)
        ->assertSet('step', 3)
        ->call('goToStep', -4)
        ->assertSet('step', 1);
});
