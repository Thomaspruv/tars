<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\PlannerProposalStatus;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\PlannerProposal;
use App\Models\Task;
use App\Support\Agents\AgentRunner;
use App\Support\Planner\PlannerAgent;

test('returns null and creates no proposal when the planner is not configured', function () {
    $proposal = app(PlannerAgent::class)->generate(now(), now()->addDays(7), AgentRunTrigger::Manual);

    expect($proposal)->toBeNull()
        ->and(PlannerProposal::count())->toBe(0);
});

test('returns null and creates no proposal when the run fails', function () {
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Failed]));
    });

    $proposal = app(PlannerAgent::class)->generate(now(), now()->addDays(7), AgentRunTrigger::Manual);

    expect($proposal)->toBeNull()
        ->and(PlannerProposal::count())->toBe(0);
});

test('returns null and creates no proposal when the json is entirely unparsable', function () {
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => 'not json at all']));
    });

    $proposal = app(PlannerAgent::class)->generate(now(), now()->addDays(7), AgentRunTrigger::Manual);

    expect($proposal)->toBeNull()
        ->and(PlannerProposal::count())->toBe(0);
});

test('creates a proposal with items for each valid task_id, never writing to the task itself', function () {
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);
    $task = Task::factory()->create(['title' => 'Rappeler le plombier', 'status' => 'todo', 'scheduled_date' => null]);

    $this->mock(AgentRunner::class, function ($mock) use ($task) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'task_id' => $task->id,
            'proposed_scheduled_date' => now()->addDays(2)->toDateString(),
            'reason' => 'Priorité haute, aucune échéance conflictuelle.',
        ]])]));
    });

    $proposal = app(PlannerAgent::class)->generate(now(), now()->addDays(7), AgentRunTrigger::Manual);

    expect($proposal)->not->toBeNull()
        ->and($proposal->status)->toBe(PlannerProposalStatus::Pending)
        ->and($proposal->items)->toHaveCount(1);

    $item = $proposal->items->first();
    expect($item->task_id)->toBe($task->id)
        ->and($item->status)->toBe(PlannerProposalStatus::Pending);

    expect($task->fresh()->scheduled_date)->toBeNull();
});

test('ignores an item referencing an unknown task_id without failing the whole batch', function () {
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);
    $task = Task::factory()->create(['status' => 'todo']);

    $this->mock(AgentRunner::class, function ($mock) use ($task) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([
            ['task_id' => 999999, 'proposed_scheduled_date' => now()->addDay()->toDateString(), 'reason' => 'x'],
            ['task_id' => $task->id, 'proposed_scheduled_date' => now()->addDay()->toDateString(), 'reason' => 'y'],
        ])]));
    });

    $proposal = app(PlannerAgent::class)->generate(now(), now()->addDays(7), AgentRunTrigger::Manual);

    expect($proposal->items)->toHaveCount(1)
        ->and($proposal->items->first()->task_id)->toBe($task->id);
});

test('ignores a task_id that is not open', function () {
    AgentConfig::factory()->create(['agent_name' => 'planner', 'enabled' => true]);
    $doneTask = Task::factory()->create(['status' => 'done']);

    $this->mock(AgentRunner::class, function ($mock) use ($doneTask) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'task_id' => $doneTask->id,
            'proposed_scheduled_date' => now()->addDay()->toDateString(),
            'reason' => 'x',
        ]])]));
    });

    $proposal = app(PlannerAgent::class)->generate(now(), now()->addDays(7), AgentRunTrigger::Manual);

    expect($proposal->items)->toHaveCount(0);
});
