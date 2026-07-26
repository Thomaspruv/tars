<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\Review;
use App\Support\Agents\AgentRunner;

test('does nothing when the reviewer agent is not configured', function () {
    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldNotReceive('run');
    });

    $this->artisan('review:generate')->assertExitCode(0);

    expect(Review::count())->toBe(0);
});

test('generates a review on a successful manual run', function () {
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(fn ($config, $system, $messages, $trigger) => $config instanceof AgentConfig && $trigger === AgentRunTrigger::Manual)
            ->andReturn(AgentRun::factory()->make(['status' => AgentRunStatus::Success, 'output' => 'Contenu de la revue.']));
    });

    $this->artisan('review:generate')->assertExitCode(0);

    expect(Review::count())->toBe(1)
        ->and(Review::first()->generated_content)->toBe('Contenu de la revue.');
});

test('does not create a review when the agent run fails', function () {
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->make(['status' => AgentRunStatus::Failed]));
    });

    $this->artisan('review:generate')->assertExitCode(1);

    expect(Review::count())->toBe(0);
});

test('passes the scheduled trigger when invoked with --scheduled', function () {
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(fn ($config, $system, $messages, $trigger) => $trigger === AgentRunTrigger::Scheduled)
            ->andReturn(AgentRun::factory()->make(['status' => AgentRunStatus::Success, 'output' => 'ok']));
    });

    $this->artisan('review:generate --scheduled')->assertExitCode(0);
});
