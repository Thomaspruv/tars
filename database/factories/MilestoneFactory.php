<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\Milestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'goal_id' => Goal::factory(),
            'title' => fake()->sentence(3),
            'target_date' => fake()->dateTimeBetween('+1 week', '+6 months'),
            'status' => 'pending',
            'position' => fake()->numberBetween(0, 10),
        ];
    }
}
