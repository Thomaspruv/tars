<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Support\Curator\CuratorAgent;

test('warns and fails when the curator is not configured and not scheduled', function () {
    $this->artisan('curator:todo')->assertExitCode(1);
});

test('skips silently when the curator is not configured and scheduled', function () {
    $this->artisan('curator:todo --scheduled')->assertExitCode(0);
});

test('processes the todo mission manually', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);

    $this->mock(CuratorAgent::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        $mock->shouldReceive('process')
            ->once()
            ->with(AgentRunTrigger::Manual, 'todo')
            ->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success]));
    });

    $this->artisan('curator:todo')->assertExitCode(0);
});

test('processes the todo mission on schedule with the scheduled trigger', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);

    $this->mock(CuratorAgent::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        $mock->shouldReceive('process')
            ->once()
            ->with(AgentRunTrigger::Scheduled, 'todo')
            ->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success]));
    });

    $this->artisan('curator:todo --scheduled')->assertExitCode(0);
});

test('fails when the run itself fails', function () {
    AgentConfig::factory()->create(['agent_name' => 'curateur', 'enabled' => true]);

    $this->mock(CuratorAgent::class, function ($mock): void {
        $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        $mock->shouldReceive('process')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Failed]));
    });

    $this->artisan('curator:todo')->assertExitCode(1);
});
