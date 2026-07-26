<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\ReviewType;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\Review;
use App\Support\Agents\AgentRunner;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

test('does nothing when the reviewer agent is not configured', function () {
    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldNotReceive('run');
    });

    $this->artisan('review:generate')->assertExitCode(0);

    expect(Review::count())->toBe(0);
});

test('generates a weekly review on a successful manual run', function () {
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(fn ($config, $system, $messages, $trigger) => $trigger === AgentRunTrigger::Manual)
            ->andReturn(AgentRun::factory()->make(['status' => AgentRunStatus::Success, 'output' => 'Contenu de la revue.']));
    });

    $this->artisan('review:generate')->assertExitCode(0);

    expect(Review::count())->toBe(1)
        ->and(Review::first()->type)->toBe(ReviewType::Weekly)
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

test('scheduled runs stay quiet outside the configured day and time', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-10 12:00:00')); // a Wednesday
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldNotReceive('run');
    });

    $this->artisan('review:generate --scheduled')->assertExitCode(0);

    expect(Review::count())->toBe(0);
});

test('scheduled runs generate the weekly review on sunday at the configured time', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-07 18:00:00')); // a Sunday
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(fn ($config, $system, $messages, $trigger) => $trigger === AgentRunTrigger::Scheduled)
            ->andReturn(AgentRun::factory()->make(['status' => AgentRunStatus::Success, 'output' => 'ok']));
    });

    $this->artisan('review:generate --scheduled')->assertExitCode(0);

    expect(Review::count())->toBe(1)
        ->and(Review::first()->type)->toBe(ReviewType::Weekly);
});

test('scheduled runs skip the weekly review when one was already generated today', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-07 18:00:00')); // a Sunday
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);
    Review::factory()->create(['type' => ReviewType::Weekly, 'created_at' => now()]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldNotReceive('run');
    });

    $this->artisan('review:generate --scheduled')->assertExitCode(0);

    expect(Review::count())->toBe(1);
});

test('scheduled runs generate the monthly review on the first of the month at the configured time', function () {
    Carbon::setTestNow(Carbon::parse('2024-02-01 18:00:00'));
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(fn ($config, $system, $messages, $trigger) => $trigger === AgentRunTrigger::Scheduled)
            ->andReturn(AgentRun::factory()->make(['status' => AgentRunStatus::Success, 'output' => 'ok']));
    });

    $this->artisan('review:generate --scheduled')->assertExitCode(0);

    expect(Review::count())->toBe(1)
        ->and(Review::first()->type)->toBe(ReviewType::Monthly)
        ->and(Review::first()->period_start->toDateString())->toBe('2024-01-01')
        ->and(Review::first()->period_end->toDateString())->toBe('2024-01-31');
});
