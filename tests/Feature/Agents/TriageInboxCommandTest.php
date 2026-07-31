<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Support\Triage\TriageAgent;

test('warns and fails when triage is not configured and not scheduled', function () {
    $this->artisan('triage:run')->assertExitCode(1);
});

test('skips silently when triage is not configured and scheduled', function () {
    $this->artisan('triage:run --scheduled')->assertExitCode(0);
});

test('processes the inbox manually', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);

    $this->mock(TriageAgent::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        $mock->shouldReceive('process')
            ->once()
            ->with(AgentRunTrigger::Manual)
            ->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success]));
    });

    $this->artisan('triage:run')->assertExitCode(0);
});

test('processes the inbox on schedule with the scheduled trigger', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);

    $this->mock(TriageAgent::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        $mock->shouldReceive('process')
            ->once()
            ->with(AgentRunTrigger::Scheduled)
            ->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success]));
    });

    $this->artisan('triage:run --scheduled')->assertExitCode(0);
});

test('fails when the run itself fails', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);

    $this->mock(TriageAgent::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        $mock->shouldReceive('process')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Failed]));
    });

    $this->artisan('triage:run')->assertExitCode(1);
});
