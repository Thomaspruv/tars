<?php

use App\Enums\AgentRunStatus;
use App\Enums\PlannerProposalStatus;
use App\Jobs\RunAgentCommandJob;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\AiProvider;
use App\Models\PlannerProposal;
use App\Models\PlannerProposalItem;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('shows an interactive card for all four agents', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::agents')
        ->assertSee('reviewer')
        ->assertSee('curateur')
        ->assertSee('triage')
        ->assertSee('planner')
        ->assertDontSee('À venir');
});

test('saves the reviewer agent configuration', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create(['is_active' => true]);

    Livewire::test('pages::agents')
        ->set('providerId.reviewer', (string) $provider->id)
        ->set('model.reviewer', 'claude-opus')
        ->set('enabled.reviewer', true)
        ->call('saveAgentConfig', 'reviewer')
        ->assertHasNoErrors();

    $config = AgentConfig::where('agent_name', 'reviewer')->firstOrFail();
    expect($config->ai_provider_id)->toBe($provider->id)
        ->and($config->model)->toBe('claude-opus')
        ->and($config->enabled)->toBeTrue();
});

test('saves the curateur agent configuration independently from reviewer', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create(['is_active' => true]);

    Livewire::test('pages::agents')
        ->set('providerId.curateur', (string) $provider->id)
        ->set('model.curateur', 'claude-haiku')
        ->set('enabled.curateur', true)
        ->call('saveAgentConfig', 'curateur')
        ->assertHasNoErrors();

    $config = AgentConfig::where('agent_name', 'curateur')->firstOrFail();
    expect($config->ai_provider_id)->toBe($provider->id)
        ->and($config->model)->toBe('claude-haiku')
        ->and(AgentConfig::where('agent_name', 'reviewer')->exists())->toBeFalse();
});

test('requires a provider and model to save an agent configuration', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::agents')
        ->set('providerId.reviewer', '')
        ->set('model.reviewer', '')
        ->call('saveAgentConfig', 'reviewer')
        ->assertHasErrors(['providerId.reviewer', 'model.reviewer']);
});

test('confirms the save with an inline message', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create(['is_active' => true]);

    Livewire::test('pages::agents')
        ->set('providerId.reviewer', (string) $provider->id)
        ->set('model.reviewer', 'claude-opus')
        ->call('saveAgentConfig', 'reviewer')
        ->assertSee('Enregistré');
});

test('does not confirm the save when validation fails', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::agents')
        ->set('providerId.reviewer', '')
        ->set('model.reviewer', '')
        ->call('saveAgentConfig', 'reviewer')
        ->assertDontSee('✓ Enregistré');
});

test('hydrates each agent form from its own existing configuration', function () {
    $this->actingAs(User::factory()->create());

    $reviewerProvider = AiProvider::factory()->create();
    $curatorProvider = AiProvider::factory()->create();
    AgentConfig::factory()->create([
        'agent_name' => 'reviewer',
        'ai_provider_id' => $reviewerProvider->id,
        'model' => 'claude-opus',
        'enabled' => false,
    ]);
    AgentConfig::factory()->create([
        'agent_name' => 'curateur',
        'ai_provider_id' => $curatorProvider->id,
        'model' => 'claude-haiku',
        'enabled' => true,
    ]);

    Livewire::test('pages::agents')
        ->assertSet('providerId.reviewer', (string) $reviewerProvider->id)
        ->assertSet('model.reviewer', 'claude-opus')
        ->assertSet('enabled.reviewer', false)
        ->assertSet('providerId.curateur', (string) $curatorProvider->id)
        ->assertSet('model.curateur', 'claude-haiku')
        ->assertSet('enabled.curateur', true);
});

test('queues the reviewer run instead of blocking on the LLM call', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'ai_provider_id' => $provider->id, 'enabled' => true]);

    Livewire::test('pages::agents')
        ->call('runReviewerNow')
        ->assertHasNoErrors()
        ->assertSee('file d\'attente');

    Queue::assertPushed(RunAgentCommandJob::class, fn ($job): bool => $job->command === 'review:generate' && $job->arguments === ['--type' => 'weekly']);
});

test('queues the reviewer run with a free period and type when provided', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'ai_provider_id' => $provider->id, 'enabled' => true]);

    Livewire::test('pages::agents')
        ->set('reviewType', 'monthly')
        ->set('reviewPeriodStart', '2024-01-01')
        ->set('reviewPeriodEnd', '2024-01-31')
        ->call('runReviewerNow')
        ->assertHasNoErrors();

    Queue::assertPushed(RunAgentCommandJob::class, fn ($job): bool => $job->command === 'review:generate'
        && $job->arguments === ['--type' => 'monthly', '--start' => '2024-01-01', '--end' => '2024-01-31']);
});

test('requires both period dates or neither when launching the reviewer', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'ai_provider_id' => $provider->id, 'enabled' => true]);

    Livewire::test('pages::agents')
        ->set('reviewPeriodStart', '2024-01-01')
        ->call('runReviewerNow')
        ->assertHasErrors(['run.reviewer']);

    Queue::assertNotPushed(RunAgentCommandJob::class);
});

test('queues the curateur todo run instead of blocking on the LLM call', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'ai_provider_id' => $provider->id, 'enabled' => true]);

    Livewire::test('pages::agents')
        ->call('runCuratorMission', 'todo')
        ->assertHasNoErrors();

    Queue::assertPushed(RunAgentCommandJob::class, fn ($job): bool => $job->command === 'curator:todo');
});

test('queues the curateur tidy run instead of blocking on the LLM call', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'ai_provider_id' => $provider->id, 'enabled' => true]);

    Livewire::test('pages::agents')
        ->call('runCuratorMission', 'tidy')
        ->assertHasNoErrors();

    Queue::assertPushed(RunAgentCommandJob::class, fn ($job): bool => $job->command === 'curator:tidy');
});

test('running a disabled/unconfigured reviewer surfaces an error and does not queue anything', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::agents')
        ->call('runReviewerNow')
        ->assertHasErrors(['run.reviewer']);

    Queue::assertNotPushed(RunAgentCommandJob::class);
});

test('running a disabled/unconfigured curateur surfaces an error and does not queue anything', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::agents')
        ->call('runCuratorMission', 'todo')
        ->assertHasErrors(['run.curateur']);

    Queue::assertNotPushed(RunAgentCommandJob::class);
});

test('pulses the badge and auto-refreshes while a run is active', function () {
    $this->actingAs(User::factory()->create());

    AgentRun::factory()->create([
        'agent_name' => 'reviewer',
        'status' => AgentRunStatus::Running,
    ]);

    Livewire::test('pages::agents')
        ->assertSee('wire:poll.5s', false)
        ->assertSee('animate-pulse-soft', false);
});

test('offers a dropdown of known models once a known provider is selected', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create(['name' => 'deepseek']);

    Livewire::test('pages::agents')
        ->set('providerId.reviewer', (string) $provider->id)
        ->assertSee('deepseek-v4-pro')
        ->assertSee('deepseek-v4-flash');
});

test('falls back to a free text model field for an unrecognised provider', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create(['name' => 'my-custom-llm']);

    Livewire::test('pages::agents')
        ->set('providerId.reviewer', (string) $provider->id)
        ->assertSeeHtml('placeholder="modèle"')
        ->assertDontSee('deepseek-v4-pro')
        ->assertDontSee('claude-sonnet-5');
});

test('shows the reviewer last run info', function () {
    $this->actingAs(User::factory()->create());

    AgentRun::factory()->create([
        'agent_name' => 'reviewer',
        'status' => AgentRunStatus::Success,
        'tokens_in' => 100,
        'tokens_out' => 50,
    ]);

    // Agent-run badges show the raw status word (design system v2, §5 "Run
    // d'agent") — mono/machine-produced content stays untranslated, unlike
    // the French labels used by the pill-shaped "Statut" badge type.
    Livewire::test('pages::agents')->assertSee('success');
});

test('filters the run history by agent, status and period', function () {
    $this->actingAs(User::factory()->create());

    AgentRun::factory()->create(['agent_name' => 'reviewer', 'status' => AgentRunStatus::Success]);
    AgentRun::factory()->create(['agent_name' => 'triage', 'status' => AgentRunStatus::Failed]);

    $component = Livewire::test('pages::agents');

    expect($component->get('runHistory'))->toHaveCount(2);

    $component->set('historyFilterAgent', 'reviewer');
    expect($component->get('runHistory'))->toHaveCount(1);

    $component->set('historyFilterAgent', '');
    $component->set('historyFilterStatus', 'failed');
    expect($component->get('runHistory'))->toHaveCount(1);
});

test('shows the run detail when a row is clicked', function () {
    $this->actingAs(User::factory()->create());

    $run = AgentRun::factory()->create([
        'agent_name' => 'reviewer',
        'output' => 'Contenu détaillé de la revue.',
    ]);

    Livewire::test('pages::agents')
        ->call('showRunDetail', $run->id)
        ->assertSet('selectedRunId', $run->id)
        ->assertSee('Contenu détaillé de la revue.');
});

test('saves the triage agent configuration independently from the others', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create(['is_active' => true]);

    Livewire::test('pages::agents')
        ->set('providerId.triage', (string) $provider->id)
        ->set('model.triage', 'claude-haiku')
        ->set('enabled.triage', true)
        ->call('saveAgentConfig', 'triage')
        ->assertHasNoErrors();

    $config = AgentConfig::where('agent_name', 'triage')->firstOrFail();
    expect($config->ai_provider_id)->toBe($provider->id)
        ->and($config->model)->toBe('claude-haiku');
});

test('saves the planner agent configuration independently from the others', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create(['is_active' => true]);

    Livewire::test('pages::agents')
        ->set('providerId.planner', (string) $provider->id)
        ->set('model.planner', 'claude-haiku')
        ->set('enabled.planner', true)
        ->call('saveAgentConfig', 'planner')
        ->assertHasNoErrors();

    $config = AgentConfig::where('agent_name', 'planner')->firstOrFail();
    expect($config->ai_provider_id)->toBe($provider->id)
        ->and($config->model)->toBe('claude-haiku');
});

test('queues the triage run instead of blocking on the LLM call', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();
    AgentConfig::factory()->create(['agent_name' => 'triage', 'ai_provider_id' => $provider->id, 'enabled' => true]);

    Livewire::test('pages::agents')
        ->call('runTriageNow')
        ->assertHasNoErrors();

    Queue::assertPushed(RunAgentCommandJob::class, fn ($job): bool => $job->command === 'triage:run');
});

test('running a disabled/unconfigured triage surfaces an error and does not queue anything', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::agents')
        ->call('runTriageNow')
        ->assertHasErrors(['run.triage']);

    Queue::assertNotPushed(RunAgentCommandJob::class);
});

test('queues the planner run with the default period', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();
    AgentConfig::factory()->create(['agent_name' => 'planner', 'ai_provider_id' => $provider->id, 'enabled' => true]);

    Livewire::test('pages::agents')
        ->call('runPlannerNow')
        ->assertHasNoErrors();

    Queue::assertPushed(RunAgentCommandJob::class, fn ($job): bool => $job->command === 'planner:generate' && $job->arguments === []);
});

test('queues the planner run with a free period when provided', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();
    AgentConfig::factory()->create(['agent_name' => 'planner', 'ai_provider_id' => $provider->id, 'enabled' => true]);

    Livewire::test('pages::agents')
        ->set('plannerPeriodStart', '2024-01-01')
        ->set('plannerPeriodEnd', '2024-01-07')
        ->call('runPlannerNow')
        ->assertHasNoErrors();

    Queue::assertPushed(RunAgentCommandJob::class, fn ($job): bool => $job->command === 'planner:generate'
        && $job->arguments === ['--start' => '2024-01-01', '--end' => '2024-01-07']);
});

test('running a disabled/unconfigured planner surfaces an error and does not queue anything', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::agents')
        ->call('runPlannerNow')
        ->assertHasErrors(['run.planner']);

    Queue::assertNotPushed(RunAgentCommandJob::class);
});

test('shows the planner proposal in the run detail modal and applies a single item', function () {
    $this->actingAs(User::factory()->create());

    $run = AgentRun::factory()->create(['agent_name' => 'planner', 'status' => AgentRunStatus::Success]);
    $proposal = PlannerProposal::factory()->create(['agent_run_id' => $run->id]);
    $task = Task::factory()->create(['status' => 'todo', 'scheduled_date' => null]);
    $item = PlannerProposalItem::factory()->create([
        'planner_proposal_id' => $proposal->id,
        'task_id' => $task->id,
        'proposed_scheduled_date' => now()->addDays(2),
        'reason' => 'Priorité haute.',
    ]);

    Livewire::test('pages::agents')
        ->call('showRunDetail', $run->id)
        ->assertSee('Priorité haute.')
        ->call('applyPlannerItem', $item->id);

    expect($task->fresh()->scheduled_date->toDateString())->toBe($item->proposed_scheduled_date->toDateString())
        ->and($item->fresh()->status)->toBe(PlannerProposalStatus::Applied);
});

test('dismisses a single planner proposal item without touching the task', function () {
    $this->actingAs(User::factory()->create());

    $run = AgentRun::factory()->create(['agent_name' => 'planner', 'status' => AgentRunStatus::Success]);
    $proposal = PlannerProposal::factory()->create(['agent_run_id' => $run->id]);
    $task = Task::factory()->create(['status' => 'todo', 'scheduled_date' => null]);
    $item = PlannerProposalItem::factory()->create([
        'planner_proposal_id' => $proposal->id,
        'task_id' => $task->id,
    ]);

    Livewire::test('pages::agents')
        ->call('showRunDetail', $run->id)
        ->call('dismissPlannerItem', $item->id);

    expect($task->fresh()->scheduled_date)->toBeNull()
        ->and($item->fresh()->status)->toBe(PlannerProposalStatus::Dismissed);
});

test('applies all pending planner proposal items at once', function () {
    $this->actingAs(User::factory()->create());

    $run = AgentRun::factory()->create(['agent_name' => 'planner', 'status' => AgentRunStatus::Success]);
    $proposal = PlannerProposal::factory()->create(['agent_run_id' => $run->id]);
    $taskA = Task::factory()->create(['status' => 'todo', 'scheduled_date' => null]);
    $taskB = Task::factory()->create(['status' => 'todo', 'scheduled_date' => null]);
    $itemA = PlannerProposalItem::factory()->create(['planner_proposal_id' => $proposal->id, 'task_id' => $taskA->id, 'proposed_scheduled_date' => now()->addDay()]);
    $itemB = PlannerProposalItem::factory()->create(['planner_proposal_id' => $proposal->id, 'task_id' => $taskB->id, 'proposed_scheduled_date' => now()->addDays(2)]);

    Livewire::test('pages::agents')
        ->call('showRunDetail', $run->id)
        ->call('applyAllPlannerItems', $proposal->id);

    expect($taskA->fresh()->scheduled_date)->not->toBeNull()
        ->and($taskB->fresh()->scheduled_date)->not->toBeNull()
        ->and($itemA->fresh()->status)->toBe(PlannerProposalStatus::Applied)
        ->and($itemB->fresh()->status)->toBe(PlannerProposalStatus::Applied)
        ->and($proposal->fresh()->status)->toBe(PlannerProposalStatus::Applied);
});

test('shows the total tokens used this month', function () {
    $this->actingAs(User::factory()->create());

    AgentRun::factory()->create([
        'tokens_in' => 100,
        'tokens_out' => 50,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    AgentRun::factory()->create([
        'tokens_in' => 1000,
        'tokens_out' => 500,
        'started_at' => now()->subMonths(2),
        'finished_at' => now()->subMonths(2),
    ]);

    Livewire::test('pages::agents')->assertSee('150 tokens ce mois-ci');
});
