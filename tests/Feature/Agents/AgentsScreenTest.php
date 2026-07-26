<?php

use App\Enums\AgentRunStatus;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\AiProvider;
use App\Models\User;
use App\Support\Agents\AgentRunner;
use Livewire\Livewire;

test('shows a greyed out card for unavailable agents', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::agents')
        ->assertSee('curateur')
        ->assertSee('triage')
        ->assertSee('planner')
        ->assertSee('À venir');
});

test('saves the reviewer agent configuration', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create(['is_active' => true]);

    Livewire::test('pages::agents')
        ->set('reviewerProviderId', (string) $provider->id)
        ->set('reviewerModel', 'claude-opus')
        ->set('reviewerEnabled', true)
        ->call('saveReviewerConfig')
        ->assertHasNoErrors();

    $config = AgentConfig::where('agent_name', 'reviewer')->firstOrFail();
    expect($config->ai_provider_id)->toBe($provider->id)
        ->and($config->model)->toBe('claude-opus')
        ->and($config->enabled)->toBeTrue();
});

test('requires a provider and model to save the reviewer configuration', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::agents')
        ->set('reviewerProviderId', '')
        ->set('reviewerModel', '')
        ->call('saveReviewerConfig')
        ->assertHasErrors(['reviewerProviderId', 'reviewerModel']);
});

test('hydrates the reviewer form from an existing configuration', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();
    AgentConfig::factory()->create([
        'agent_name' => 'reviewer',
        'ai_provider_id' => $provider->id,
        'model' => 'claude-haiku',
        'enabled' => false,
    ]);

    Livewire::test('pages::agents')
        ->assertSet('reviewerProviderId', (string) $provider->id)
        ->assertSet('reviewerModel', 'claude-haiku')
        ->assertSet('reviewerEnabled', false);
});

test('runs the reviewer agent now via the review:generate command', function () {
    $this->actingAs(User::factory()->create());

    $provider = AiProvider::factory()->create();
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'ai_provider_id' => $provider->id]);

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->make([
            'agent_name' => 'reviewer',
            'status' => AgentRunStatus::Success,
            'output' => 'Revue générée.',
        ]));
    });

    Livewire::test('pages::agents')->call('runReviewerNow');
});

test('shows the reviewer last run info', function () {
    $this->actingAs(User::factory()->create());

    AgentRun::factory()->create([
        'agent_name' => 'reviewer',
        'status' => AgentRunStatus::Success,
        'tokens_in' => 100,
        'tokens_out' => 50,
    ]);

    Livewire::test('pages::agents')->assertSee('Réussi');
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
