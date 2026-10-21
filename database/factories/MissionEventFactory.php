<?php

namespace Database\Factories;

use App\Models\Mission;
use App\Models\MissionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MissionEvent>
 */
class MissionEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mission_id' => Mission::factory(),
            'seq' => fake()->numberBetween(1, 100),
            'type' => 'assistant',
            'subtype' => null,
            'tool_use_id' => null,
            'parent_tool_use_id' => null,
            'tool_name' => null,
            'text' => fake()->sentence(),
            'payload' => [],
            'occurred_at' => now(),
        ];
    }
}
