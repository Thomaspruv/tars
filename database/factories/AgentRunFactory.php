<?php

namespace Database\Factories;

use App\Enums\AgentRunStatus;
use App\Enums\AgentRunTrigger;
use App\Models\AgentRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 week', 'now');

        return [
            'agent_name' => fake()->randomElement(['reviewer', 'triage', 'planner', 'researcher', 'assistant']),
            'trigger' => AgentRunTrigger::Manual,
            'status' => AgentRunStatus::Success,
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'input' => [],
            'output' => null,
            'error' => null,
            'tokens_in' => fake()->numberBetween(200, 5000),
            'tokens_out' => fake()->numberBetween(100, 2000),
            'started_at' => $startedAt,
            'finished_at' => (clone $startedAt)->modify('+30 seconds'),
        ];
    }
}
