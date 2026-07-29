<?php

namespace Database\Factories;

use App\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => 'active',
            'target_date' => fake()->dateTimeBetween('+1 month', '+1 year'),
            'review_frequency' => 'weekly',
            'position' => fake()->numberBetween(0, 10),
        ];
    }
}
