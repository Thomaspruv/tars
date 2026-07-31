<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\InboxSuggestionStatus;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Task;
use App\Support\Agents\AgentRunner;
use App\Support\Triage\TriageAgent;

test('returns null and creates no suggestion when triage is not configured', function () {
    InboxItem::factory()->create();

    $run = app(TriageAgent::class)->process(AgentRunTrigger::Manual);

    expect($run)->toBeNull()
        ->and(InboxSuggestion::count())->toBe(0);
});

test('marks the run failed and writes nothing when the json is invalid', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    InboxItem::factory()->create();

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => 'not json at all']));
    });

    $run = app(TriageAgent::class)->process(AgentRunTrigger::Manual);

    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and(InboxSuggestion::count())->toBe(0);
});

test('marks the run failed when an item is missing required fields', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    InboxItem::factory()->create();

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([['inbox_item_id' => 1]])]));
    });

    $run = app(TriageAgent::class)->process(AgentRunTrigger::Manual);

    expect($run->status)->toBe(AgentRunStatus::Failed)
        ->and(InboxSuggestion::count())->toBe(0);
});

test('creates a pending suggestion at medium confidence', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    $item = InboxItem::factory()->create(['content' => 'Acheter du lait']);

    $this->mock(AgentRunner::class, function ($mock) use ($item) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'inbox_item_id' => $item->id,
            'suggested_type' => 'note',
            'suggested_fields' => ['content' => 'Acheter du lait'],
            'confidence' => 'medium',
            'reason' => 'Information sans action claire.',
        ]])]));
    });

    app(TriageAgent::class)->process(AgentRunTrigger::Manual);

    $suggestion = InboxSuggestion::firstOrFail();
    expect($suggestion->inbox_item_id)->toBe($item->id)
        ->and($suggestion->status)->toBe(InboxSuggestionStatus::Pending);
});

test('auto-applies a task suggestion at high confidence with no conflict', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    $item = InboxItem::factory()->create(['content' => 'Rappeler Marc']);

    $this->mock(AgentRunner::class, function ($mock) use ($item) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'inbox_item_id' => $item->id,
            'suggested_type' => 'task',
            'suggested_fields' => ['title' => 'Rappeler Marc'],
            'confidence' => 'high',
            'reason' => 'Action évidente.',
        ]])]));
    });

    app(TriageAgent::class)->process(AgentRunTrigger::Manual);

    $suggestion = InboxSuggestion::firstOrFail();
    expect($suggestion->status)->toBe(InboxSuggestionStatus::AutoApplied)
        ->and(Task::where('title', 'Rappeler Marc')->exists())->toBeTrue()
        ->and($item->fresh()->processed_at)->not->toBeNull();
});

test('falls back to pending when an auto-applicable task duplicates an open task', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    Task::factory()->create(['title' => 'Rappeler Marc', 'status' => 'todo']);
    $item = InboxItem::factory()->create(['content' => 'Rappeler Marc']);

    $this->mock(AgentRunner::class, function ($mock) use ($item) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'inbox_item_id' => $item->id,
            'suggested_type' => 'task',
            'suggested_fields' => ['title' => 'Rappeler Marc'],
            'confidence' => 'high',
            'reason' => 'Action évidente.',
        ]])]));
    });

    app(TriageAgent::class)->process(AgentRunTrigger::Manual);

    expect(InboxSuggestion::firstOrFail()->status)->toBe(InboxSuggestionStatus::Pending)
        ->and(Task::where('title', 'Rappeler Marc')->count())->toBe(1);
});

test('never auto-applies at medium confidence', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    $item = InboxItem::factory()->create(['content' => 'Courir un semi']);

    $this->mock(AgentRunner::class, function ($mock) use ($item) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'inbox_item_id' => $item->id,
            'suggested_type' => 'goal',
            'suggested_fields' => ['title' => 'Courir un semi'],
            'confidence' => 'medium',
            'reason' => 'Peut-être un objectif.',
        ]])]));
    });

    app(TriageAgent::class)->process(AgentRunTrigger::Manual);

    expect(InboxSuggestion::firstOrFail()->status)->toBe(InboxSuggestionStatus::Pending)
        ->and(Goal::count())->toBe(0);
});

test('does not recreate a suggestion identical to one rejected less than 30 days ago', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    $item = InboxItem::factory()->create();

    InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'suggested_type' => 'task',
        'status' => InboxSuggestionStatus::Rejected,
        'created_at' => now()->subDays(5),
    ]);

    $this->mock(AgentRunner::class, function ($mock) use ($item) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'inbox_item_id' => $item->id,
            'suggested_type' => 'task',
            'suggested_fields' => ['title' => 'x'],
            'confidence' => 'high',
            'reason' => 'x',
        ]])]));
    });

    app(TriageAgent::class)->process(AgentRunTrigger::Manual);

    expect(InboxSuggestion::where('status', InboxSuggestionStatus::Pending)->count())->toBe(0)
        ->and(InboxSuggestion::where('status', InboxSuggestionStatus::AutoApplied)->count())->toBe(0);
});

test('ignores an item with an unknown inbox_item_id without failing the run', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    InboxItem::factory()->create();

    $this->mock(AgentRunner::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'inbox_item_id' => 999999,
            'suggested_type' => 'task',
            'suggested_fields' => ['title' => 'x'],
            'confidence' => 'high',
            'reason' => 'x',
        ]])]));
    });

    $run = app(TriageAgent::class)->process(AgentRunTrigger::Manual);

    expect($run->status)->toBe(AgentRunStatus::Success)
        ->and(InboxSuggestion::count())->toBe(0);
});

test('skips items with suggested_type none', function () {
    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    $item = InboxItem::factory()->create();

    $this->mock(AgentRunner::class, function ($mock) use ($item) {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->create(['status' => AgentRunStatus::Success, 'output' => json_encode([[
            'inbox_item_id' => $item->id,
            'suggested_type' => 'none',
        ]])]));
    });

    app(TriageAgent::class)->process(AgentRunTrigger::Manual);

    expect(InboxSuggestion::count())->toBe(0);
});
