<?php

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Enums\ReviewType;
use App\Models\AgentConfig;
use App\Models\AgentRun;
use App\Models\Review;
use App\Support\Agents\AgentRunner;
use App\Support\Review\ReviewerAgent;

test('returns null and creates no review when the agent is not configured', function () {
    $review = app(ReviewerAgent::class)->generate(ReviewType::Weekly, now()->subDays(7), now(), AgentRunTrigger::Manual);

    expect($review)->toBeNull()
        ->and(Review::count())->toBe(0);
});

test('returns null and creates no review when the run fails', function () {
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->make(['status' => AgentRunStatus::Failed]));
    });

    $review = app(ReviewerAgent::class)->generate(ReviewType::Weekly, now()->subDays(7), now(), AgentRunTrigger::Manual);

    expect($review)->toBeNull()
        ->and(Review::count())->toBe(0);
});

test('strips the json block from the markdown and stores up to 3 proposed decisions', function () {
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);

    $output = <<<'MD'
    ## Le pouls
    Tout va bien.

    ```json
    [
        {"question": "On met en pause l'objectif X ?", "goal": "Courir un semi-marathon", "entity": null},
        {"question": "On relance le projet Y ?", "goal": null, "entity": "SARL Dupont"},
        {"question": "Troisième décision ?", "goal": null, "entity": null},
        {"question": "Une quatrième décision qui ne doit pas apparaître ?", "goal": null, "entity": null}
    ]
    ```
    MD;

    $this->mock(AgentRunner::class, function ($mock) use ($output): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->make(['status' => AgentRunStatus::Success, 'output' => $output]));
    });

    $periodStart = now()->subDays(7);
    $periodEnd = now();

    $review = app(ReviewerAgent::class)->generate(ReviewType::Weekly, $periodStart, $periodEnd, AgentRunTrigger::Manual);

    expect($review)->not->toBeNull()
        ->and($review->generated_content)->toContain('## Le pouls')
        ->and($review->generated_content)->not->toContain('```json')
        ->and($review->proposed_decisions)->toHaveCount(3)
        ->and($review->proposed_decisions[0]['question'])->toBe("On met en pause l'objectif X ?")
        ->and($review->proposed_decisions[0]['goal'])->toBe('Courir un semi-marathon')
        ->and($review->proposed_decisions[0]['response'])->toBeNull()
        ->and($review->proposed_decisions[1]['entity'])->toBe('SARL Dupont')
        ->and($review->type)->toBe(ReviewType::Weekly);
});

test('stores an empty decisions list when the output has no json block', function () {
    AgentConfig::factory()->create(['agent_name' => 'reviewer', 'enabled' => true]);

    $this->mock(AgentRunner::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andReturn(AgentRun::factory()->make(['status' => AgentRunStatus::Success, 'output' => '## Le pouls\nTout va bien.']));
    });

    $review = app(ReviewerAgent::class)->generate(ReviewType::Weekly, now()->subDays(7), now(), AgentRunTrigger::Manual);

    expect($review->proposed_decisions)->toBe([]);
});
