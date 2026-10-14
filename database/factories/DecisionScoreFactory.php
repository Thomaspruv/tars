<?php

namespace Database\Factories;

use App\Models\DecisionCriterion;
use App\Models\DecisionOption;
use App\Models\DecisionScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DecisionScore>
 */
class DecisionScoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'decision_option_id' => DecisionOption::factory(),
            'decision_criterion_id' => DecisionCriterion::factory(),
            'score' => fake()->numberBetween(0, 5),
        ];
    }
}
