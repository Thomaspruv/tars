<?php

namespace Database\Factories;

use App\Models\DecisionCriterion;
use App\Models\OpenDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DecisionCriterion>
 */
class DecisionCriterionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'open_decision_id' => OpenDecision::factory(),
            'label' => fake()->words(2, true),
            'weight' => fake()->numberBetween(1, 5),
            'position' => 0,
        ];
    }
}
