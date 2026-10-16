<?php

use App\Enums\DecisionSource;
use App\Enums\OpenDecisionStatus;
use App\Models\Decision;
use App\Models\DecisionCriterion;
use App\Models\DecisionOption;
use App\Models\DecisionScore;
use App\Models\Goal;
use App\Models\OpenDecision;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('decisions.index'))->assertRedirect(route('login'));
});

test('it lists the decisions waiting for an arbitration', function () {
    $this->actingAs(User::factory()->create());

    OpenDecision::factory()->create(['question' => 'Changer de voiture ?']);

    $this->get(route('decisions.index'))->assertSee('Changer de voiture ?');
});

test('it shows an empty state when nothing is waiting', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('decisions.index'))->assertSee('Rien à trancher.', false);
});

test('it creates a decision and lands on its comparator', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create();

    Livewire::test('pages::decisions')
        ->call('openCreateModal')
        ->set('question', 'Déménager à Nantes ?')
        ->set('goalId', $goal->id)
        ->call('createDecision')
        ->assertRedirect(route('decisions.show', OpenDecision::firstWhere('question', 'Déménager à Nantes ?')));

    $decision = OpenDecision::firstWhere('question', 'Déménager à Nantes ?');

    expect($decision->status)->toBe(OpenDecisionStatus::Open)
        ->and($decision->goal_id)->toBe($goal->id);
});

test('it requires a question', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::decisions')
        ->set('question', '')
        ->call('createDecision')
        ->assertHasErrors(['question' => 'required']);

    expect(OpenDecision::count())->toBe(0);
});

test('it seeds the classic criteria on an empty board', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('seedClassicCriteria');

    expect($decision->criteria()->pluck('label')->all())->toBe(['Coût', 'Temps', 'Risque', 'Envie']);
});

test('it adds options and criteria to the board', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->set('newOptionLabel', 'Garder la 208')
        ->call('addOption')
        ->assertSet('newOptionLabel', '')
        ->set('newCriterionLabel', 'Coût')
        ->set('newCriterionWeight', '5')
        ->call('addCriterion')
        ->assertSet('newCriterionLabel', '');

    expect($decision->options()->pluck('label')->all())->toBe(['Garder la 208'])
        ->and($decision->criteria()->first()->weight)->toBe(5);
});

test('it scores a cell and computes the weighted total', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create(['label' => 'A']);
    $cost = DecisionCriterion::factory()->for($decision)->create(['label' => 'Coût', 'weight' => 5]);
    $time = DecisionCriterion::factory()->for($decision)->create(['label' => 'Temps', 'weight' => 3]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->set("grid.{$option->id}.{$cost->id}", '3')
        ->set("grid.{$option->id}.{$time->id}", '4');

    // 3×5 + 4×3
    expect($decision->fresh()->scores())->toBe([$option->id => 27]);
});

test('an unscored option has no score at all', function () {
    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create();
    DecisionCriterion::factory()->for($decision)->create(['weight' => 4]);

    expect($decision->scores())->toBe([$option->id => null])
        ->and($decision->leadingOption())->toBeNull();
});

test('a tie leaves no leading option', function () {
    $decision = OpenDecision::factory()->create();
    $criterion = DecisionCriterion::factory()->for($decision)->create(['weight' => 2]);

    foreach (['A', 'B'] as $label) {
        $option = DecisionOption::factory()->for($decision)->create(['label' => $label]);
        DecisionScore::factory()->create([
            'decision_option_id' => $option->id,
            'decision_criterion_id' => $criterion->id,
            'score' => 4,
        ]);
    }

    expect($decision->leadingOption())->toBeNull();

    $best = $decision->options->first();
    DecisionScore::where('decision_option_id', $best->id)->update(['score' => 5]);

    expect($decision->fresh()->leadingOption()->id)->toBe($best->id);
});

test('changing a weight re-ranks the options', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $criterion = DecisionCriterion::factory()->for($decision)->create(['weight' => 1]);
    $option = DecisionOption::factory()->for($decision)->create();
    DecisionScore::factory()->create([
        'decision_option_id' => $option->id,
        'decision_criterion_id' => $criterion->id,
        'score' => 4,
    ]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->assertSet("weights.{$criterion->id}", '1')
        ->set("weights.{$criterion->id}", '5');

    expect($decision->fresh()->scores())->toBe([$option->id => 20]);
});

test('choosing an option writes the journal and closes the decision', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create();
    $decision = OpenDecision::factory()->create([
        'question' => 'Changer de voiture ?',
        'goal_id' => $goal->id,
    ]);
    $keep = DecisionOption::factory()->for($decision)->create(['label' => 'Garder la 208']);
    $buy = DecisionOption::factory()->for($decision)->create(['label' => 'Acheter une break']);
    $criterion = DecisionCriterion::factory()->for($decision)->create(['label' => 'Coût', 'weight' => 4]);
    DecisionScore::factory()->create(['decision_option_id' => $keep->id, 'decision_criterion_id' => $criterion->id, 'score' => 5]);
    DecisionScore::factory()->create(['decision_option_id' => $buy->id, 'decision_criterion_id' => $criterion->id, 'score' => 2]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('chooseOption', $keep->id);

    $decision->refresh();
    $journalEntry = Decision::sole();

    expect($decision->status)->toBe(OpenDecisionStatus::Decided)
        ->and($decision->chosen_option_id)->toBe($keep->id)
        ->and($decision->closed_at)->not->toBeNull()
        ->and($decision->decision_id)->toBe($journalEntry->id)
        ->and($journalEntry->content)->toBe('Changer de voiture ? → Garder la 208')
        ->and($journalEntry->source)->toBe(DecisionSource::Arbitration)
        ->and($journalEntry->goal_id)->toBe($goal->id)
        ->and($journalEntry->context)->toContain('Garder la 208 : 20 pts')
        ->and($journalEntry->context)->toContain('Acheter une break : 8 pts');
});

test('a decided board no longer accepts edits', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create(['label' => 'A']);
    $criterion = DecisionCriterion::factory()->for($decision)->create(['weight' => 3]);

    $component = Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('chooseOption', $option->id)
        ->set("grid.{$option->id}.{$criterion->id}", '5')
        ->set('newOptionLabel', 'B')
        ->call('addOption');

    $component->call('removeCriterion', $criterion->id);

    expect(DecisionScore::count())->toBe(0)
        ->and($decision->options()->count())->toBe(1)
        ->and($decision->criteria()->count())->toBe(1)
        ->and(Decision::count())->toBe(1);
});

test('a dropped decision leaves the journal alone and can be reopened', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();

    $component = Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('dropDecision');

    expect($decision->fresh()->status)->toBe(OpenDecisionStatus::Dropped)
        ->and(Decision::count())->toBe(0);

    $component->call('reopenDecision');

    expect($decision->fresh()->status)->toBe(OpenDecisionStatus::Open)
        ->and($decision->fresh()->closed_at)->toBeNull();
});

test('closed decisions are listed apart from the ones still open', function () {
    $this->actingAs(User::factory()->create());

    OpenDecision::factory()->create(['question' => 'Question ouverte ?']);
    OpenDecision::factory()->create([
        'question' => 'Question classée ?',
        'status' => OpenDecisionStatus::Dropped,
    ]);

    Livewire::test('pages::decisions')
        ->assertSee('Question ouverte ?')
        ->assertSee('Question classée ?')
        ->assertSee('Classées');
});

test('deleting an option takes its scores with it', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create();
    $criterion = DecisionCriterion::factory()->for($decision)->create();
    DecisionScore::factory()->create([
        'decision_option_id' => $option->id,
        'decision_criterion_id' => $criterion->id,
        'score' => 3,
    ]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('removeOption', $option->id);

    expect($decision->options()->count())->toBe(0)
        ->and(DecisionScore::count())->toBe(0);
});

test('the comparator route resolves the decision and renders the board', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create(['question' => 'Reprendre le piano ?']);
    DecisionOption::factory()->for($decision)->create(['label' => 'Cours en présentiel']);
    DecisionCriterion::factory()->for($decision)->create(['label' => 'Coût', 'weight' => 4]);

    $this->get(route('decisions.show', $decision))
        ->assertOk()
        ->assertSee('Reprendre le piano ?')
        ->assertSee('Cours en présentiel')
        ->assertSee('Trancher');
});

test('the space badge counts only the decisions still open', function () {
    $this->actingAs(User::factory()->create());

    OpenDecision::factory()->count(2)->create();
    OpenDecision::factory()->create(['status' => OpenDecisionStatus::Decided]);
    OpenDecision::factory()->create(['status' => OpenDecisionStatus::Dropped]);

    $response = $this->get(route('today'));

    // Abricot, pas vert : ce compte est le mien, pas celui d'un agent. Il est
    // porté par l'espace Objectifs, dont les Décisions sont un onglet.
    expect($response->getContent())->toMatch('/Objectifs<\/span>\s*<span[^>]*bg-\(--acbg\)[^>]*>2<\/span>/');
});

test('it renames an option and a criterion', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create(['label' => 'A']);
    $criterion = DecisionCriterion::factory()->for($decision)->create(['label' => 'Coût']);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->set("optionLabels.{$option->id}", '  Garder la 208  ')
        ->set("criterionLabels.{$criterion->id}", 'Coût réel');

    expect($option->fresh()->label)->toBe('Garder la 208')
        ->and($criterion->fresh()->label)->toBe('Coût réel');
});

test('an emptied label falls back to the stored one', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create(['label' => 'Garder la 208']);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->set("optionLabels.{$option->id}", '   ')
        ->assertSet("optionLabels.{$option->id}", 'Garder la 208');

    expect($option->fresh()->label)->toBe('Garder la 208');
});

test('it stores the per-option notes and the context', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create(['context' => null]);
    $option = DecisionOption::factory()->for($decision)->create();

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->set("optionNotes.{$option->id}", 'Devis à 2 400 € en janvier')
        ->set('contextDraft', 'Budget plafonné à 5 000 €');

    expect($option->fresh()->notes)->toBe('Devis à 2 400 € en janvier')
        ->and($decision->fresh()->context)->toBe('Budget plafonné à 5 000 €');

    Livewire::test('pages::decisions.show', ['decision' => $decision->fresh()])
        ->set('contextDraft', '');

    expect($decision->fresh()->context)->toBeNull();
});

test('the board hydrates from what is already stored', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create(['context' => 'Le contexte']);
    $option = DecisionOption::factory()->for($decision)->create(['notes' => 'Une note']);
    $rated = DecisionCriterion::factory()->for($decision)->create(['weight' => 4]);
    $unrated = DecisionCriterion::factory()->for($decision)->create(['weight' => 2]);
    DecisionScore::factory()->create([
        'decision_option_id' => $option->id,
        'decision_criterion_id' => $rated->id,
        'score' => 3,
    ]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->assertSet("grid.{$option->id}.{$rated->id}", '3')
        ->assertSet("grid.{$option->id}.{$unrated->id}", '')
        ->assertSet("weights.{$rated->id}", '4')
        ->assertSet("optionNotes.{$option->id}", 'Une note')
        ->assertSet('contextDraft', 'Le contexte');
});

test('clearing a cell takes the option back to no score', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create();
    $criterion = DecisionCriterion::factory()->for($decision)->create(['weight' => 3]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->set("grid.{$option->id}.{$criterion->id}", '4')
        ->set("grid.{$option->id}.{$criterion->id}", '');

    expect($decision->fresh()->scores())->toBe([$option->id => null]);
});

test('scores and weights are clamped to their range', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create();
    $criterion = DecisionCriterion::factory()->for($decision)->create(['weight' => 3]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->set("grid.{$option->id}.{$criterion->id}", '9')
        ->set("weights.{$criterion->id}", '42');

    expect(DecisionScore::sole()->score)->toBe(5)
        ->and($criterion->fresh()->weight)->toBe(5);
});

test('a board never touches another board rows', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $criterion = DecisionCriterion::factory()->for($decision)->create(['weight' => 3]);

    $other = OpenDecision::factory()->create();
    $otherOption = DecisionOption::factory()->for($other)->create(['label' => 'Pas la mienne']);
    $otherCriterion = DecisionCriterion::factory()->for($other)->create(['label' => 'Pas le mien', 'weight' => 2]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->set("grid.{$otherOption->id}.{$criterion->id}", '5')
        ->set("weights.{$otherCriterion->id}", '5')
        ->set("optionLabels.{$otherOption->id}", 'Renommée')
        ->call('removeOption', $otherOption->id)
        ->call('removeCriterion', $otherCriterion->id)
        ->call('chooseOption', $otherOption->id);

    expect(DecisionScore::count())->toBe(0)
        ->and($otherOption->fresh()->label)->toBe('Pas la mienne')
        ->and($otherCriterion->fresh()->weight)->toBe(2)
        ->and($decision->fresh()->status)->toBe(OpenDecisionStatus::Open)
        ->and(Decision::count())->toBe(0);
});

test('the classic criteria are only seeded onto an empty board', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    DecisionCriterion::factory()->for($decision)->create(['label' => 'Le mien']);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('seedClassicCriteria');

    expect($decision->criteria()->pluck('label')->all())->toBe(['Le mien']);
});

test('deleting a criterion takes its scores with it', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create();
    $criterion = DecisionCriterion::factory()->for($decision)->create();
    DecisionScore::factory()->create([
        'decision_option_id' => $option->id,
        'decision_criterion_id' => $criterion->id,
        'score' => 3,
    ]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('removeCriterion', $criterion->id);

    expect($decision->criteria()->count())->toBe(0)
        ->and(DecisionScore::count())->toBe(0);
});

test('a decided board shows its verdict instead of the arbitration buttons', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create(['label' => 'Garder la 208']);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('chooseOption', $option->id)
        ->assertSee('Tranchée le')
        ->assertSee('Garder la 208')
        ->assertDontSee('Trancher')
        ->assertDontSee('Abandonner');
});

test('a decided decision cannot be reopened, a dropped one can', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create();

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('chooseOption', $option->id)
        ->call('reopenDecision');

    expect($decision->fresh()->status)->toBe(OpenDecisionStatus::Decided);
});

test('the closest deadline comes first and the undated decisions last', function () {
    $this->actingAs(User::factory()->create());

    OpenDecision::factory()->create(['question' => 'Sans échéance ?', 'due_date' => null]);
    OpenDecision::factory()->create(['question' => 'Dans un mois ?', 'due_date' => now()->addMonth()]);
    OpenDecision::factory()->create(['question' => 'Demain ?', 'due_date' => now()->addDay()]);

    $this->get(route('decisions.index'))
        ->assertSeeInOrder(['Demain ?', 'Dans un mois ?', 'Sans échéance ?']);
});

test('an overdue decision is flagged on the list', function () {
    $this->actingAs(User::factory()->create());

    OpenDecision::factory()->create(['due_date' => now()->subWeek()]);

    $this->get(route('decisions.index'))->assertSee('à trancher avant le');
});

test('a removed option can be restored with its scores', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create(['label' => 'Coworking', 'notes' => 'à confirmer', 'position' => 2]);
    $criterion = DecisionCriterion::factory()->for($decision)->create(['weight' => 4]);
    DecisionScore::factory()->create([
        'decision_option_id' => $option->id,
        'decision_criterion_id' => $criterion->id,
        'score' => 5,
    ]);

    $component = Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('removeOption', $option->id)
        ->assertSee('Coworking')
        ->assertSee('Rétablir');

    expect($decision->options()->count())->toBe(0);

    $component->call('restoreLastRemoval');

    $restored = $decision->options()->sole();

    expect($restored->label)->toBe('Coworking')
        ->and($restored->notes)->toBe('à confirmer')
        ->and($restored->position)->toBe(2)
        ->and($restored->scores()->sole()->score)->toBe(5)
        ->and($component->get('lastRemoval'))->toBeNull();
});

test('a removed criterion can be restored with its weight and scores', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create();
    $criterion = DecisionCriterion::factory()->for($decision)->create(['label' => 'Calme', 'weight' => 5, 'position' => 1]);
    DecisionScore::factory()->create([
        'decision_option_id' => $option->id,
        'decision_criterion_id' => $criterion->id,
        'score' => 4,
    ]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('removeCriterion', $criterion->id)
        ->call('restoreLastRemoval');

    $restored = $decision->criteria()->sole();

    expect($restored->label)->toBe('Calme')
        ->and($restored->weight)->toBe(5)
        ->and($restored->position)->toBe(1)
        ->and($restored->scores()->sole()->score)->toBe(4)
        ->and($restored->scores()->sole()->decision_option_id)->toBe($option->id);
});

test('restoring skips a score whose other end is gone', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create();
    $criterion = DecisionCriterion::factory()->for($decision)->create();
    DecisionScore::factory()->create([
        'decision_option_id' => $option->id,
        'decision_criterion_id' => $criterion->id,
        'score' => 3,
    ]);

    Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('removeOption', $option->id)
        ->call('removeCriterion', $criterion->id)
        ->call('restoreLastRemoval');

    // Le critère revient ; sa note visait une option qui n'existe plus, elle
    // est abandonnée plutôt que ressuscitée dans le vide.
    expect($decision->criteria()->count())->toBe(1)
        ->and($decision->options()->count())->toBe(0)
        ->and(DecisionScore::count())->toBe(0);
});

test('dismissing the restore banner drops the kept row for good', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create();

    $component = Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('removeOption', $option->id)
        ->call('dismissLastRemoval')
        ->assertDontSee('Rétablir');

    $component->call('restoreLastRemoval');

    expect($decision->options()->count())->toBe(0)
        ->and($component->get('lastRemoval'))->toBeNull();
});

test('a closed board offers nothing to restore', function () {
    $this->actingAs(User::factory()->create());

    $decision = OpenDecision::factory()->create();
    $option = DecisionOption::factory()->for($decision)->create();

    $component = Livewire::test('pages::decisions.show', ['decision' => $decision])
        ->call('dropDecision')
        ->call('removeOption', $option->id);

    expect($component->get('lastRemoval'))->toBeNull()
        ->and($decision->options()->count())->toBe(1);
});
