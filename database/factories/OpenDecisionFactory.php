<?php

namespace Database\Factories;

use App\Enums\OpenDecisionStatus;
use App\Models\OpenDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpenDecision>
 */
class OpenDecisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question' => rtrim(fake()->sentence(6), '.').' ?',
            'context' => null,
            'status' => OpenDecisionStatus::Open,
            'goal_id' => null,
            'entity_id' => null,
            'due_date' => null,
        ];
    }
}
