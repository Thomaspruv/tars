<?php

namespace Database\Factories;

use App\Enums\DecisionSource;
use App\Models\Decision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Decision>
 */
class DecisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content' => fake()->sentence(6),
            'context' => null,
            'source' => DecisionSource::Conversation,
            'review_id' => null,
            'goal_id' => null,
            'entity_id' => null,
            'decided_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
