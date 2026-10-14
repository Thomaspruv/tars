<?php

namespace Database\Factories;

use App\Models\DecisionOption;
use App\Models\OpenDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DecisionOption>
 */
class DecisionOptionFactory extends Factory
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
            'notes' => null,
            'position' => 0,
        ];
    }
}
