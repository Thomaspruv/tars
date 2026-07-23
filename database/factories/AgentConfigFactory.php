<?php

namespace Database\Factories;

use App\Models\AgentConfig;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentConfig>
 */
class AgentConfigFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_name' => fake()->unique()->randomElement(['reviewer', 'triage', 'planner', 'researcher', 'assistant']),
            'ai_provider_id' => AiProvider::factory(),
            'model' => 'deepseek-chat',
            'enabled' => true,
        ];
    }
}
