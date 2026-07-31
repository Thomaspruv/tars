<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\PlannerProposal;
use App\Models\Task;
use App\Support\Agents\AgentRunner;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

test('warns and fails manually when the planner agent is not configured', function () {
    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldNotReceive('run');
    });

    $this->artisan('planner:generate')->assertExitCode(1);

    expect(PlannerProposal::count())->toBe(0);
});

test('skips silently when scheduled and the planner agent is not configured', function () {
    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldNotReceive('run');
    });

    $this->artisan('planner:generate --scheduled')->assertExitCode(0);

    expect(PlannerProposal::count())->toBe(0);
});

test('generates a proposal on a successful manual run', function () {
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);
    $task = Task::factory()->create(['status' => 'todo']);

    $this->mock(AgentRunner::class, function ($mock) use ($task): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(fn ($config, $system, $messages, $trigger) => $trigger === AgentRunTrigger::Manual)
            ->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
                'task_id' => $task->id,
                'proposed_scheduled_date' => now()->addDay()->toDateString(),
                'reason' => 'x',
            ]])]));
    });

    $this->artisan('planner:generate')->assertExitCode(0);

    expect(PlannerProposal::count())->toBe(1);
});

test('fails when the run itself fails', function () {
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Failed]));
    });

    $this->artisan('planner:generate')->assertExitCode(1);

    expect(PlannerProposal::count())->toBe(0);
});

test('scheduled runs stay quiet outside the configured day and time', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-10 12:00:00')); // a Wednesday
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldNotReceive('run');
    });

    $this->artisan('planner:generate --scheduled')->assertExitCode(0);

    expect(PlannerProposal::count())->toBe(0);
});

test('scheduled runs generate a proposal on sunday at the configured time', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-07 18:00:00')); // a Sunday
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);
    $task = Task::factory()->create(['status' => 'todo']);

    $this->mock(AgentRunner::class, function ($mock) use ($task): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(fn ($config, $system, $messages, $trigger) => $trigger === AgentRunTrigger::Scheduled)
            ->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
                'task_id' => $task->id,
                'proposed_scheduled_date' => now()->addDay()->toDateString(),
                'reason' => 'x',
            ]])]));
    });

    $this->artisan('planner:generate --scheduled')->assertExitCode(0);

    expect(PlannerProposal::count())->toBe(1);
});

test('scheduled runs skip when a proposal was already generated today', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-07 18:00:00')); // a Sunday
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);
    PlannerProposal::factory()->create(['created_at' => now()]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldNotReceive('run');
    });

    $this->artisan('planner:generate --scheduled')->assertExitCode(0);

    expect(PlannerProposal::count())->toBe(1);
});

test('a manual run can request a free period via --start and --end', function () {
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([])]));
    });

    $this->artisan('planner:generate --start=2024-01-01 --end=2024-01-15')->assertExitCode(0);

    expect(PlannerProposal::first()->period_start->toDateString())->toBe('2024-01-01')
        ->and(PlannerProposal::first()->period_end->toDateString())->toBe('2024-01-15');
});
