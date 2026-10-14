<?php

use App\Enums\DecisionSource;
use App\Enums\OpenDecisionStatus;
use App\Models\Decision;
use App\Models\OpenDecision;
use App\Models\User;
use Database\Seeders\DecisionSeeder;

beforeEach(function () {
    (new DecisionSeeder)->run();
});

test('it seeds open decisions at every stage of the comparator', function () {
    $open = OpenDecision::where('status', OpenDecisionStatus::Open)->get();

    expect($open)->toHaveCount(4);

    $fullyScored = $open->firstWhere('question', 'Quel hébergeur pour le nouveau site ?');
    expect($fullyScored->options)->toHaveCount(3);
    expect($fullyScored->criteria)->toHaveCount(3);
    expect($fullyScored->scores())->not->toContain(null);
    expect($fullyScored->leadingOption()?->label)->toBe('Laravel Cloud');

    $partiallyScored = $open->firstWhere('question', 'Reprendre le bail du bureau ou passer en coworking ?');
    $unratedOption = $partiallyScored->options->firstWhere('label', 'Full télétravail');
    expect($partiallyScored->scores()[$unratedOption->id])->toBeNull();

    $unscored = $open->firstWhere('question', 'Où partir en vacances cet été ?');
    expect(array_filter($unscored->scores()))->toBeEmpty();
    expect($unscored->leadingOption())->toBeNull();

    $withoutOptions = $open->firstWhere('question', 'Faut-il recruter un alternant à la rentrée ?');
    expect($withoutOptions->options)->toBeEmpty();
});

test('it seeds a decided decision wired to its journal entry', function () {
    $decided = OpenDecision::where('status', OpenDecisionStatus::Decided)->sole();

    expect($decided->chosenOption?->label)->toBe('Cabinet Martin');
    expect($decided->closed_at)->not->toBeNull();

    $journalEntry = $decided->decision;
    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->source)->toBe(DecisionSource::Arbitration);
    expect($journalEntry->content)->toBe($decided->question.' → Cabinet Martin');
    expect($journalEntry->context)->toContain('Comparateur —')->toContain('Retenu : Cabinet Martin');
});

test('it seeds a dropped decision with no journal entry', function () {
    $dropped = OpenDecision::where('status', OpenDecisionStatus::Dropped)->sole();

    expect($dropped->question)->toBe('Changer de banque pro ?');
    expect($dropped->decision_id)->toBeNull();
    expect($dropped->closed_at)->not->toBeNull();
    expect($dropped->options)->toHaveCount(2);
});

test('it seeds journal entries coming from outside the comparator', function () {
    expect(Decision::where('source', DecisionSource::Review)->count())->toBe(1);
    expect(Decision::where('source', DecisionSource::Conversation)->count())->toBe(1);
});

test('the decisions screen renders the seeded data', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('decisions.index'))
        ->assertOk()
        ->assertSee('Quel hébergeur pour le nouveau site ?')
        ->assertSee('Changer de banque pro ?');
});
