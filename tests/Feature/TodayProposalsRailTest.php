<?php

use App\Enums\OpenDecisionStatus;
use App\Enums\PlannerProposalStatus;
use App\Enums\SuggestionConfidence;
use App\Models\DecisionCriterion;
use App\Models\DecisionOption;
use App\Models\DecisionScore;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Milestone;
use App\Models\OpenDecision;
use App\Models\PlannerProposal;
use App\Models\PlannerProposalItem;
use App\Models\ProposalDismissal;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(fn () => test()->actingAs(User::factory()->create()));

afterEach(fn () => Carbon::setTestNow());

test('an empty week proposes nothing at all', function () {
    expect(Livewire::test('proposals-rail')->get('proposals'))->toHaveCount(0);
});

test('the rail aggregates the four sources, in reading order', function () {
    Review::factory()->create(['completed_at' => null, 'generated_content' => 'La semaine a tenu. Le reste suit.']);

    $item = InboxItem::factory()->create();
    InboxSuggestion::factory()->create(['inbox_item_id' => $item->id, 'confidence' => SuggestionConfidence::High]);

    OpenDecision::factory()->create([
        'question' => 'Garder la voiture ?',
        'status' => OpenDecisionStatus::Open,
        'due_date' => today()->addDays(5),
    ]);

    Goal::factory()->create(['title' => 'Vendre la maison', 'status' => 'active', 'created_at' => now()->subMonths(3)]);

    $proposals = Livewire::test('proposals-rail')->get('proposals');

    expect($proposals)->toHaveCount(4);
    expect($proposals->pluck('primaryLabel')->all())->toBe(['Lire', "Voir l'Inbox", 'Peser', 'Voir les jalons']);
    expect($proposals[0]->text)->toContain('La semaine a tenu.');
    expect($proposals[0]->text)->toContain("Je l'ouvre ?");
    expect($proposals[3]->text)->toContain('Rien depuis 3 semaines et aucun jalon');
});

test('the inbox proposal says what Hermès pre-qualified', function () {
    $first = InboxItem::factory()->create();
    $second = InboxItem::factory()->create();

    InboxSuggestion::factory()->create(['inbox_item_id' => $first->id, 'suggested_type' => 'event']);
    InboxSuggestion::factory()->create(['inbox_item_id' => $second->id, 'suggested_type' => 'note']);

    $proposal = Livewire::test('proposals-rail')->get('proposals')->first();

    expect($proposal->text)->toBe("J'ai pré-qualifié les 2 : 1 événements, 1 notes.");
});

test('the decision proposal names the option in the lead with its score', function () {
    $decision = OpenDecision::factory()->create([
        'question' => 'Garder la voiture ?',
        'status' => OpenDecisionStatus::Open,
        'due_date' => today()->addDays(3),
    ]);

    $keep = DecisionOption::factory()->create(['open_decision_id' => $decision->id, 'label' => 'La garder']);
    $sell = DecisionOption::factory()->create(['open_decision_id' => $decision->id, 'label' => 'La vendre']);
    $criterion = DecisionCriterion::factory()->create(['open_decision_id' => $decision->id, 'weight' => 3]);

    DecisionScore::factory()->create(['decision_option_id' => $keep->id, 'decision_criterion_id' => $criterion->id, 'score' => 2]);
    DecisionScore::factory()->create(['decision_option_id' => $sell->id, 'decision_criterion_id' => $criterion->id, 'score' => 5]);

    $proposal = Livewire::test('proposals-rail')->get('proposals')->first();

    expect($proposal->text)->toBe('Garder la voiture ? : La vendre (15 pts).');
    expect($proposal->secondaryLabel)->toBe('Ignorer');
});

test('a decision due later than a fortnight stays out of the rail', function () {
    OpenDecision::factory()->create([
        'status' => OpenDecisionStatus::Open,
        'due_date' => today()->addDays(30),
    ]);

    expect(Livewire::test('proposals-rail')->get('proposals'))->toHaveCount(0);
});

test('a goal with a milestone, or with recent activity, is not nagged about', function () {
    $withMilestone = Goal::factory()->create(['status' => 'active', 'created_at' => now()->subMonths(3)]);
    Milestone::factory()->create(['goal_id' => $withMilestone->id]);

    $alive = Goal::factory()->create(['status' => 'active', 'created_at' => now()->subMonths(3)]);
    Task::factory()->create(['goal_id' => $alive->id, 'created_at' => now()->subDays(2)]);

    Goal::factory()->create(['status' => 'active', 'created_at' => now()->subDays(4)]);

    expect(Livewire::test('proposals-rail')->get('proposals'))->toHaveCount(0);
});

test('ignoring a proposal keeps it quiet for a week, not forever', function () {
    Carbon::setTestNow('2026-10-14 09:00:00');

    $review = Review::factory()->create(['completed_at' => null]);

    $component = Livewire::test('proposals-rail');
    expect($component->get('proposals'))->toHaveCount(1);

    $component->call('dismiss', 'review:'.$review->id);

    expect($component->get('proposals'))->toHaveCount(0);
    expect(ProposalDismissal::where('key', 'review:'.$review->id)->first()->until->toDateString())
        ->toBe('2026-10-21');

    Carbon::setTestNow('2026-10-22 09:00:00');

    expect(Livewire::test('proposals-rail')->get('proposals'))->toHaveCount(1);
});

test('the rail never says more than four things', function () {
    Review::factory()->create(['completed_at' => null]);
    InboxItem::factory()->count(3)->create();
    OpenDecision::factory()->create(['status' => OpenDecisionStatus::Open, 'due_date' => today()->addDay()]);
    Goal::factory()->count(3)->create(['status' => 'active', 'created_at' => now()->subMonths(3)]);

    expect(Livewire::test('proposals-rail')->get('proposals')->count())->toBeLessThanOrEqual(4);
});

test('a task late by more than a week folds into a single block, out of the day', function () {
    $longOverdue = Task::factory()->create(['title' => 'Relancer le syndic', 'due_date' => today()->subDays(10)]);
    $recentlyLate = Task::factory()->create(['title' => 'Payer la TVA', 'due_date' => today()->subDays(2)]);
    $today = Task::factory()->create(['title' => 'Appeler le plombier', 'scheduled_date' => today()]);

    $component = Livewire::test('pages::today');

    expect($component->get('longOverdueTasks')->pluck('id')->all())->toBe([$longOverdue->id]);
    expect($component->get('tasksToday')->pluck('id')->all())->not->toContain($longOverdue->id);
    expect($component->get('restOfDay')->pluck('id')->all())->not->toContain($longOverdue->id);

    $component->assertSee('1 tâche attend depuis plus d\'une semaine')
        // Texte littéral du gabarit : il n'est pas échappé dans le HTML.
        ->assertSee('La plupart ont perdu leur date, pas leur raison d\'être.', false)
        ->assertSee('Replanifier pour moi')
        ->assertSee('Voir les 1');

    // Le retard récent reste dans la journée, sans pilule abricot.
    $html = $component->html();
    expect($html)->toContain($recentlyLate->title)
        ->and($html)->toContain($today->title)
        ->and($html)->not->toContain('depuis '.$recentlyLate->due_date->translatedFormat('l'));
});

test('no late block when nothing has waited a week', function () {
    Task::factory()->create(['due_date' => today()->subDays(6)]);

    Livewire::test('pages::today')->assertDontSee('depuis plus d\'une semaine');
});

test('the planner proposal is accepted or refused, never applied on its own', function () {
    $task = Task::factory()->create(['title' => 'Relancer le syndic', 'due_date' => today()->subDays(10)]);

    $proposal = PlannerProposal::factory()->create(['status' => PlannerProposalStatus::Pending]);
    $item = PlannerProposalItem::factory()->create([
        'planner_proposal_id' => $proposal->id,
        'task_id' => $task->id,
        'proposed_scheduled_date' => today()->addDays(2),
        'status' => PlannerProposalStatus::Pending,
    ]);

    Livewire::test('pages::today')
        ->assertSee('Ce que je propose')
        ->assertSee('Appliquer')
        ->assertSee('Non merci')
        ->call('applyPlannerProposal', $proposal->id);

    expect($task->fresh()->scheduled_date->toDateString())->toBe(today()->addDays(2)->toDateString());
    expect($item->fresh()->status)->toBe(PlannerProposalStatus::Applied);
    expect($proposal->fresh()->status)->toBe(PlannerProposalStatus::Applied);
});

test('refusing the planner proposal leaves every task where it was', function () {
    $task = Task::factory()->create(['due_date' => today()->subDays(10), 'scheduled_date' => null]);

    $proposal = PlannerProposal::factory()->create(['status' => PlannerProposalStatus::Pending]);
    PlannerProposalItem::factory()->create([
        'planner_proposal_id' => $proposal->id,
        'task_id' => $task->id,
        'proposed_scheduled_date' => today()->addDays(2),
        'status' => PlannerProposalStatus::Pending,
    ]);

    Livewire::test('pages::today')->call('dismissPlannerProposal', $proposal->id);

    expect($task->fresh()->scheduled_date)->toBeNull();
    expect($proposal->fresh()->status)->toBe(PlannerProposalStatus::Dismissed);
});

test('replanning says so when the planner is not configured', function () {
    Task::factory()->create(['due_date' => today()->subDays(10)]);

    Livewire::test('pages::today')
        ->call('replanLate')
        ->assertHasErrors('planner');
});

test('the day kicker carries the date, the count and the streak', function () {
    Task::factory()->done()->create(['scheduled_date' => today(), 'completed_at' => now()]);
    Task::factory()->create(['scheduled_date' => today()]);

    $kicker = Livewire::test('pages::today')->get('kicker');

    expect($kicker)->toContain(ucfirst(now()->translatedFormat('l j F')))
        ->and($kicker)->toContain('1 sur 2')
        ->and($kicker)->toContain('la série tient à');
});
