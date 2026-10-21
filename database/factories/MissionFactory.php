<?php

namespace Database\Factories;

use App\Enums\MissionStatus;
use App\Models\Mission;
use App\Models\TeamAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mission>
 */
class MissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_agent_id' => TeamAgent::factory(),
            'source' => 'thomas',
            'skill_slug' => null,
            'prompt' => fake()->sentence(),
            'status' => MissionStatus::Queued,
            'workspace_path' => '/tmp/vault',
            'model' => 'sonnet',
            'effort' => null,
            'max_budget_usd' => '2.00',
            // Toute mission réelle en a un dès son premier message : c'est lui
            // qui permet de reprendre la conversation.
            'session_id' => fake()->uuid(),
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function fromHermes(): self
    {
        return $this->state(['source' => 'hermes']);
    }

    public function watching(int $everyMinutes = 30): self
    {
        return $this->state([
            'watch_every_minutes' => $everyMinutes,
            'watch_until' => now()->addDay(),
            'watch_next_at' => now()->subMinute(),
        ]);
    }

    public function running(): self
    {
        return $this->state([
            'status' => MissionStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function succeeded(): self
    {
        return $this->state([
            'status' => MissionStatus::Succeeded,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'result' => 'C\'est fait.',
            'cost_usd' => '0.0412',
            'num_turns' => 3,
        ]);
    }
}
